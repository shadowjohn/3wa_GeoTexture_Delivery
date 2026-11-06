"""Road-only openings preserve source roofs and grounded supports."""
import copy
import tempfile
from pathlib import Path
import numpy as np
from shapely.geometry import Polygon
from test_procedural import procedural, ring, parse_glb, accessor


def request():
    return {'source_key':'taichung-8','build_id':'station','source_hash':'a'*64,'recipe_key':'procedural-tw-v1',
            'feature':{'type':'Feature','geometry':{'type':'Polygon','coordinates':[ring([(0,0),(20,0),(20,20),(0,20)])]},
                       'properties':{'height':15,'geometry_correction':{'version':1,'source_key':'taichung-8','build_id':'station',
                            'base_m':6,'width_scale':1,'estimated':True,'name':'test','attribution':'OSM',
                            'geometry':{'type':'Polygon','coordinates':[ring([(8,-5),(12,-5),(12,25),(8,25)])]}}}}}


def test_opening_shell_and_roof():
    req=request()
    with tempfile.TemporaryDirectory() as d:
        out=Path(d)/'station.glb';result=procedural.generate_request(req,out);doc,bin=parse_glb(out)
        assert result['geometry_correction']==req['feature']['properties']['geometry_correction']
        areas={0:0,6:0};roof=0
        for primitive in doc['meshes'][0]['primitives']:
            p=accessor(doc,bin,primitive['attributes']['POSITION']);idx=accessor(doc,bin,primitive['indices']).ravel()
            normals=accessor(doc,bin,primitive['attributes']['NORMAL'])
            for indices in idx.reshape(-1,3):
                tri=p[indices];n=normals[indices[0]]
                area=np.linalg.norm(np.cross(tri[1]-tri[0],tri[2]-tri[0]))/2
                if n[1]<-.9:
                    y=int(round(tri[0,1]));assert np.allclose(tri[:,1],y);areas[y]+=area
                if n[1]>.9: assert np.allclose(tri[:,1],15);roof+=area
                if abs(n[1])<.1:
                    # Exposed cut sides are only below clearance, no internal walls above it.
                    x=tri[:,0]
                    if np.allclose(x,2,atol=.01) or np.allclose(x,-2,atol=.01): assert max(tri[:,1])<=6
        assert abs(roof-400)<.02
        assert abs(areas[0]-320)<.02 and abs(areas[6]-80)<.02
        assert np.isfinite(p).all()


def test_invalid_clearance_and_identity():
    import pytest
    for field,value in [('base_m',15),('base_m',0),('base_m',float('nan')),('width_scale',0),('width_scale',True),('source_key','new-taipei-8418'),('build_id','other')]:
        req=request();req['feature']['properties']['geometry_correction'][field]=value
        with tempfile.TemporaryDirectory() as d, pytest.raises(ValueError): procedural.generate_request(req,Path(d)/'x.glb')
