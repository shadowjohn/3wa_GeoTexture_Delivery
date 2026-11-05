#!/usr/bin/env python3
"""以自己的 Mapillary Token 執行最多三棟官方建物的少量真實試轉。"""
import argparse
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault('MPLBACKEND', 'Agg')
os.environ.setdefault('MPLCONFIGDIR', str(ROOT / '.venv-orthofacade' / 'mpl-config'))

from pipeline.orthofacade_adapter import (MapillaryClient, ROOT, cache_images, load_discovery, load_frozen_features, neighbours_from_php,
    pilot_directory, rank_candidates, redact_message, run_official, select_panos, write_json)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', default='tamsui-pilot-001')
    parser.add_argument('--job-id')
    parser.add_argument('--build-id')
    parser.add_argument('--bbox', default='121.412,25.1695,121.424,25.1815')
    parser.add_argument('--app-db', type=Path, default=ROOT / 'db' / 'app.sqlite')
    parser.add_argument('--source-db', type=Path, default=ROOT.parent / '建物資料' / 'F_新北建物8418.db')
    parser.add_argument('--token-file', type=Path, default=ROOT / 'db' / 'mapillary-token.txt')
    parser.add_argument('--pano-count', type=int, default=6)
    parser.add_argument('--candidate-limit', type=int, default=3)
    parser.add_argument('--discover-only', action='store_true')
    parser.add_argument('--tex-ppb', type=int, choices=[8, 16, 32, 64], default=64)
    parser.add_argument('--inspection', action='store_true')
    args = parser.parse_args()
    token = ''
    try:
        if not (1 <= args.candidate_limit <= 3 and 3 <= args.pano_count <= 6):
            raise ValueError('pilot 最多三棟、每棟三至六張全景。')
        bbox = tuple(map(float, args.bbox.split(',')))
        if len(bbox) != 4 or not (-180 <= bbox[0] < bbox[2] <= 180 and -90 <= bbox[1] < bbox[3] <= 90):
            raise ValueError('bbox 必須是 west,south,east,north。')
        if bbox[2]-bbox[0] > .05 or bbox[3]-bbox[1] > .05:
            raise ValueError('pilot metadata bbox 每軸最多 0.05 度。')
        work = pilot_directory(ROOT, args.run)
        work.mkdir(parents=True, exist_ok=True)
        token = args.token_file.read_text(encoding='utf-8-sig').strip()
        client = MapillaryClient(token)
        discovery = work / 'discovery.json'
        metadata = load_discovery(discovery, bbox, client)
        features = load_frozen_features(args.app_db, args.job_id)
        candidates = rank_candidates(features, metadata)
        if args.build_id:
            candidates = [row for row in candidates if row['build_id'] == args.build_id]
        write_json(work / 'candidates.json', [{key: value for key, value in row.items() if key != 'feature'} for row in candidates])
        if not candidates:
            raise ValueError('現有任務沒有近距離街景且幾何簡單的候選，請先評估鄰近建物建立 pilot 任務。')
        print(json.dumps({'metadata': len(metadata), 'candidates': [{key: value for key, value in row.items() if key != 'feature'} for row in candidates[:3]]}, ensure_ascii=False), flush=True)
        if args.discover_only:
            return 0
        results = []
        # 下載依 image ID 共用；每個候選仍依自己的位置選片，不能沿用前一棟觀測。
        shared = work
        for index, candidate in enumerate(candidates[:args.candidate_limit]):
            feature = candidate['feature']
            current = work if index == 0 else pilot_directory(ROOT, args.run + '-candidate-' + str(index + 1))
            current.mkdir(parents=True, exist_ok=True)
            selected = select_panos(feature, metadata, args.pano_count)
            images = cache_images(client, selected, shared)
            if current != shared:
                import shutil
                shutil.copytree(shared / 'cache', current / 'cache', dirs_exist_ok=True)
            bounds = __import__('shapely.geometry', fromlist=['shape']).shape(feature['geometry']).bounds
            neighbour_bbox = [bounds[0]-.0016, bounds[1]-.0016, bounds[2]+.0016, bounds[3]+.0016]
            neighbours = neighbours_from_php(args.source_db, neighbour_bbox)
            print(f"pilot building={candidate['build_id']} hash={feature['properties']['source_hash']} neighbours={len(neighbours)}", flush=True)
            pilot = run_official(feature, neighbours, images, current, token, tex_ppb=args.tex_ppb, inspection=args.inspection)
            counts = {'walls': len(pilot['walls']), 'textured': sum(wall['status'] == 'textured' for wall in pilot['walls'])}
            result = {'build_id': pilot['build_id'], 'pilot': str(current / 'pilot.json'), **counts}
            results.append(result)
            write_json(work / 'attempts.json', results)
            print(json.dumps(result, ensure_ascii=False), flush=True)
            if counts['textured']:
                return 0
        return 3
    except Exception as error:
        print(json.dumps({'error': redact_message(str(error), [token]), 'type': type(error).__name__}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')
    raise SystemExit(main())
