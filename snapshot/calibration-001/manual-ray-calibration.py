"""從本資料包重現人工射線診斷；不讀資料庫、不下載，也不匯出模型。"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, __version__ as pillow_version


BUNDLE = Path(__file__).resolve().parent


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8'))


def direction_of_pixels(axes, pixels, width, height):
    pixels = np.asarray(pixels, dtype=float)
    azimuth = (pixels[:, 0] / width - .5) * 2 * np.pi
    elevation = (.5 - pixels[:, 1] / height) * np.pi
    camera = np.stack([np.sin(azimuth) * np.cos(elevation), -np.sin(elevation),
                       np.cos(azimuth) * np.cos(elevation)], axis=-1)
    return camera @ axes


def solve(axes, roof, foot, height, width, image_height):
    directions = direction_of_pixels(axes, [roof, foot], width, image_height)
    target = np.array([0, 0, height])
    lengths = np.linalg.lstsq(np.column_stack((directions[0], -directions[1])), target, rcond=None)[0]
    if np.any(lengths <= 0):
        raise ValueError('Control rays do not form a positive vertical segment')
    top, base = lengths[0] * directions[0], lengths[1] * directions[1]
    delta_xy = (top[:2] + base[:2]) / 2
    return {'roof_pixel': roof, 'foot_pixel': foot, 'assumed_height_m': height,
            'ray_lengths_m': lengths.tolist(), 'roof_ray': directions[0].tolist(), 'foot_ray': directions[1].tolist(),
            'horizontal_displacement_m': delta_xy.tolist(), 'horizontal_distance_m': float(np.linalg.norm(delta_xy)),
            'camera_height_above_foot_m': float(-base[2]),
            'vertical_segment_fit_error_m': float(np.linalg.norm(top - base - target))}


def resample(pano, camera_c, axes, wall_a, wall_b, normal, projection, z_base):
    """對物理牆面逐像素重新投影，沒有裁已生成的貼圖或調整模型 UV。"""
    ppm = int(projection['pixels_per_meter'])
    s_l, s_r = float(projection['s_l']), float(projection['s_r'])
    wall_height = float(projection['official_height_m'])
    width, height = max(1, round(s_r - s_l)) * ppm, max(1, round(wall_height)) * ppm
    s = s_l + (np.arange(width) + .5) * (s_r - s_l) / width
    h = wall_height - (np.arange(height) + .5) * wall_height / height
    tangent = (wall_b - wall_a) / np.linalg.norm(wall_b - wall_a)
    grid_s, grid_h = np.meshgrid(s, h)
    xy = wall_a + grid_s[..., None] * tangent + float(projection['epsilon_m']) * normal
    points = np.concatenate([xy, (z_base + grid_h)[..., None]], axis=-1)
    local = (points - camera_c) @ axes.T
    length = np.linalg.norm(local, axis=-1)
    u = .5 + np.arctan2(local[..., 0], local[..., 2]) / (2 * np.pi)
    v = .5 + np.arcsin(np.clip(local[..., 1] / np.maximum(length, 1e-12), -1, 1)) / np.pi
    image_height, image_width = pano.shape[:2]
    map_x = np.mod(u * image_width - .5, image_width).astype(np.float32)
    map_y = np.clip(v * image_height - .5, 0, image_height - 1).astype(np.float32)
    rgb = cv2.remap(pano, map_x, map_y, cv2.INTER_LINEAR, borderMode=cv2.BORDER_WRAP)
    valid = (v >= projection['valid_v_range'][0]) & (v <= projection['valid_v_range'][1])
    rgb[~valid] = 255
    return Image.fromarray(rgb)


def run(out):
    data = read_json(BUNDLE / 'inputs.json')
    attribution = read_json(BUNDLE / data['attribution_file'])
    source = data['source_image']
    image_bytes = (BUNDLE / source['file']).read_bytes()
    source_hash = hashlib.sha256(image_bytes).hexdigest()
    if source_hash != source['sha256'] or source_hash != attribution['original_sha256']:
        raise ValueError('Original panorama SHA256 mismatch; do not calibrate a resized or modified image')
    bgr = cv2.imdecode(np.frombuffer(image_bytes, dtype=np.uint8), cv2.IMREAD_COLOR)
    if bgr is None or (bgr.shape[1], bgr.shape[0]) != (source['width'], source['height']):
        raise ValueError('Original panorama dimensions do not match inputs.json')
    pano = np.ascontiguousarray(bgr[..., ::-1])
    camera_c = np.asarray(data['cameras']['global']['C'], dtype=float)
    axes = np.asarray(data['cameras']['global']['axes'], dtype=float)
    wall_a, wall_b = np.asarray(data['wall']['a']), np.asarray(data['wall']['b'])
    normal = np.asarray(data['wall']['normal'])
    if not np.allclose(axes @ axes.T, np.eye(3), atol=1e-6) or not np.isclose(np.linalg.norm(normal), 1):
        raise ValueError('Camera axes and wall normal must be orthonormal/unit length')
    projection = data['projection']
    official_height = projection['official_height_m']
    current_z_base = projection['current_z_base']
    plane_point = wall_a + projection['epsilon_m'] * normal
    current_normal_distance = float((camera_c[:2] - plane_point) @ normal)
    controls = []
    for control in data['controls']:
        roof, foot = control['roof_pixel'], control['foot_pixel']
        result = solve(axes, roof, foot, official_height, source['width'], source['height'])
        result.update({'id': control['id'],
                       'normal_distance_m': float(-np.array(result['horizontal_displacement_m']) @ normal),
                       'foot_z': float(camera_c[2] - result['camera_height_above_foot_m']),
                       'semantics': 'roof fascia to visible foot/step; not a verified official corner'})
        perturbations = [solve(axes, [roof[0], roof[1] + a], [foot[0], foot[1] + b], official_height,
                               source['width'], source['height']) for a in (-3, 0, 3) for b in (-3, 0, 3)]
        distances = [-np.array(v['horizontal_displacement_m']) @ normal for v in perturbations]
        heights = [v['camera_height_above_foot_m'] for v in perturbations]
        result['pixel_y_plus_minus_3_sensitivity'] = {
            'normal_distance_m': [float(min(distances)), float(max(distances))],
            'camera_height_m': [min(heights), max(heights)]}
        controls.append(result)
    estimated_distance = float(np.mean([c['normal_distance_m'] for c in controls]))
    estimated_camera_height = float(np.mean([c['camera_height_above_foot_m'] for c in controls]))
    calibrated_z_base = float(camera_c[2] - estimated_camera_height)
    normal_delta = estimated_distance - current_normal_distance
    calibrated_c = camera_c.copy()
    calibrated_c[:2] += normal * normal_delta

    # 此處只保留沿牆原值作為診斷對照，沒有足夠資料求出正確沿牆位置。
    variants = [('Current global / default ground', camera_c, current_z_base),
                ('ONLY height from manual vertical rays', camera_c, calibrated_z_base),
                ('Normal distance + height from rays', calibrated_c, calibrated_z_base)]
    images = [(title, c, z, resample(pano, c, axes, wall_a, wall_b, normal, projection, z))
              for title, c, z in variants]
    proof = Image.new('RGB', (sum(image.width for _, _, _, image in images), images[0][3].height + 136), 'white')
    draw = ImageDraw.Draw(proof)
    x = 0
    for title, candidate, base, image in images:
        proof.paste(image, (x, 108))
        draw.text((x + 10, 10), title, fill='black')
        draw.text((x + 10, 30), f'z_base={base:.3f}; camera XY={candidate[0]:.3f},{candidate[1]:.3f}', fill='black')
        draw.text((x + 10, 50), f'Same physical wall / official H={official_height:.2f}m / original panorama', fill='black')
        draw.text((x + 10, 70), 'DIAGNOSTIC ONLY: no known corner; along-wall position unverified', fill='red')
        x += image.width
    credit = f'Mapillary {source["id"]} / {attribution["author"]["username"]} / {attribution["license"]} / modified diagnostic'
    draw.text((10, proof.height - 20), credit, fill='black')
    out.mkdir(parents=True, exist_ok=True)
    proof.save(out / 'proof-comparison.png')

    crop = data['proof_control_crop']
    selected = Image.fromarray(pano).crop(tuple(crop)).resize(((crop[2] - crop[0]) * 3, (crop[3] - crop[1]) * 3))
    draw = ImageDraw.Draw(selected)
    for control, color in zip(controls, ['red', 'blue']):
        line = []
        for key in ('roof_pixel', 'foot_pixel'):
            px, py = control[key]
            x, y = (px - crop[0]) * 3, (py - crop[1]) * 3
            line.append((x, y))
            draw.ellipse((x - 7, y - 7, x + 7, y + 7), outline=color, width=3)
            draw.text((x + 8, y - 12), f'{control["id"]} {key} {px},{py}', fill=color, stroke_width=1, stroke_fill='white')
        draw.line(line, fill=color, width=2)
    marked = Image.new('RGB', (selected.width, selected.height + 72), 'white')
    marked.paste(selected, (0, 48))
    annotation = ImageDraw.Draw(marked)
    annotation.text((10, 10), 'DIAGNOSTIC CONTROL PICKS - official footprint corners NOT VERIFIED', fill='red')
    annotation.text((10, 28), 'Labels are original 6080x3040 panorama pixels; view below is enlarged only.', fill='black')
    annotation.text((10, marked.height - 18), credit, fill='black')
    marked.save(out / 'control-pixels.png')
    result = {'status': 'manual_calibration_diagnostic_only', 'pano_id': source['id'], 'wall_key': data['wall']['key'],
              'source_image_sha256': source_hash, 'controls': controls,
              'current': {'camera_C': camera_c.tolist(), 'z_base': current_z_base,
                          'effective_camera_height_m': float(camera_c[2] - current_z_base),
                          'normal_distance_to_render_plane_m': current_normal_distance},
              'calibrated_proof': {'camera_C': calibrated_c.tolist(), 'z_base': calibrated_z_base,
                                  'camera_height_m': estimated_camera_height, 'normal_distance_m': estimated_distance,
                                  'normal_camera_translation_m': normal_delta},
              'limitations': data['control_assumptions'],
              'runtime': {'numpy': np.__version__, 'pillow': pillow_version, 'opencv': cv2.__version__}}
    (out / 'summary.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    return result


def main():
    parser = argparse.ArgumentParser(description='Offline, unverified manual calibration diagnostic only')
    parser.add_argument('--out', type=Path, required=True, help='Directory for diagnostic PNG/JSON outputs')
    args = parser.parse_args()
    result = run(args.out.resolve())
    print(json.dumps({'status': result['status'], 'source_image_sha256': result['source_image_sha256'],
                      'calibrated_proof': result['calibrated_proof']}, ensure_ascii=False))


if __name__ == '__main__':
    main()
