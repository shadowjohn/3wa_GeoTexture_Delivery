#!/usr/bin/env python3
"""以自己的 Client Token 抽樣查詢全景覆蓋；不下載影像或使用上游 demo token。"""
import argparse
import json
import math
import os
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


def parse_bbox(value):
    try:
        west, south, east, north = map(float, value.split(','))
    except (ValueError, TypeError):
        raise ValueError('bbox 必須為 west,south,east,north 四個數值。') from None
    if not all(map(math.isfinite, (west, south, east, north))):
        raise ValueError('bbox 不可包含非有限數值。')
    if not (-180 <= west < east <= 180 and -90 <= south < north <= 90):
        raise ValueError('bbox 順序或經緯度範圍不正確。')
    if east - west > 0.05 or north - south > 0.05:
        raise ValueError('初次覆蓋查詢每軸最多 0.05 度，請先選小範圍。')
    return west, south, east, north


def load_token(path, env_token):
    token = env_token or (path.read_text(encoding='utf-8-sig') if path.is_file() else '')
    token = token.strip()
    if not token:
        raise ValueError('尚未設定自己的 Mapillary Client Token；請設定 MAPILLARY_TOKEN 或存入 db/mapillary-token.txt。')
    if '\r' in token or '\n' in token or len(token) > 8192:
        raise ValueError('Token 必須為單行，請確認複製的是 Client Token。')
    return token


def query_coverage(bbox, token, *, opener=urlopen):
    query = urlencode({
        'bbox': ','.join(map(str, bbox)), 'is_pano': 'true', 'limit': 100,
        'fields': 'id,is_pano,captured_at,computed_geometry,computed_rotation,computed_altitude,sfm_cluster',
    })
    # 放 Authorization header，避免 Token 出現在 URL／存取紀錄中。
    request = Request('https://graph.mapillary.com/images?' + query, headers={
        'Authorization': 'OAuth ' + token, 'Accept': 'application/json',
        'User-Agent': '3wa-GeoTexture-coverage/0.1',
    })
    with opener(request, timeout=20) as response:
        raw = response.read(1048577)
    if len(raw) > 1048576:
        raise ValueError('Mapillary 回應超過 1 MB，請縮小範圍。')
    body = json.loads(raw)
    rows = body.get('data') if isinstance(body, dict) else None
    if not isinstance(rows, list) or any(not isinstance(row, dict) for row in rows):
        raise ValueError('Mapillary 未回傳有效的影像清單。')
    def has_pose(row):
        geometry = row.get('computed_geometry') or {}
        coordinates = geometry.get('coordinates')
        rotation = row.get('computed_rotation')
        return (geometry.get('type') == 'Point' and isinstance(coordinates, list) and len(coordinates) >= 2
                and isinstance(rotation, list) and len(rotation) == 3
                and isinstance(row.get('computed_altitude'), (int, float)))
    return {
        'source': 'Mapillary', 'bbox': list(bbox), 'sample_only': True,
        'images_returned': len(rows), 'usable_pose': sum(map(has_pose, rows)),
        'with_sfm_cluster': sum(bool((row.get('sfm_cluster') or {}).get('id')) for row in rows),
        'sample_image_ids': [str(row['id']) for row in rows[:10] if 'id' in row],
        'has_more': bool((body.get('paging') or {}).get('next')),
        'note': '只確認全景中繼資料；相機/SfM 可下載性、牆面可見性與貼皮品質仍需實際驗證。',
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bbox', required=True, help='west,south,east,north，初次請選小範圍')
    parser.add_argument('--token-file', type=Path, default=Path(__file__).resolve().parents[1] / 'db' / 'mapillary-token.txt')
    args = parser.parse_args()
    token = ''
    try:
        bbox = parse_bbox(args.bbox)
        token = load_token(args.token_file, os.environ.get('MAPILLARY_TOKEN'))
    except (ValueError, OSError) as error:
        print(json.dumps({'error': str(error)}, ensure_ascii=False))
        return 2
    try:
        print(json.dumps({'data': query_coverage(bbox, token)}, ensure_ascii=False))
        return 0
    except HTTPError as error:
        print(json.dumps({'error': f'Mapillary HTTP {error.code}；請檢查 Client Token、Read 權限與查詢範圍。'}, ensure_ascii=False))
    except (ValueError, OSError, URLError) as error:
        print(json.dumps({'error': str(error).replace(token, '[redacted]')}, ensure_ascii=False))
    return 1


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    raise SystemExit(main())
