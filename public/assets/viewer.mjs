import * as THREE from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {GLTFLoader} from 'three/addons/loaders/GLTFLoader.js';
import {qualityNotices} from './model-quality.mjs';

const stage = document.getElementById('viewer-stage');
const message = document.getElementById('viewer-message');
const fitButton = document.getElementById('fit-model');
const rotateButton = document.getElementById('rotate-model');
let root = null, grid = null, requestId = 0;
const scene = new THREE.Scene();
scene.background = new THREE.Color('#eef2f4');
const renderer = new THREE.WebGLRenderer({antialias: true});
renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
renderer.outputColorSpace = THREE.SRGBColorSpace;
stage.appendChild(renderer.domElement);
const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 10000);
camera.position.set(25, 20, 30);
const controls = new OrbitControls(camera, renderer.domElement);
controls.addEventListener('change', render);
scene.add(new THREE.HemisphereLight(0xffffff, 0xa5abb0, 2));
const light = new THREE.DirectionalLight(0xffffff, 2);
light.position.set(20, 40, 25);
scene.add(light);
const loader = new GLTFLoader();

function render() { renderer.render(scene, camera); }
function resize() {
  const box = stage.getBoundingClientRect();
  renderer.setSize(Math.max(1, box.width), Math.max(1, box.height), false);
  camera.aspect = box.width / Math.max(1, box.height);
  camera.updateProjectionMatrix();
  if (root) fit(true); else render();
}
new ResizeObserver(resize).observe(stage);

function disposeModel(object) {
  const disposed = new Set();
  object?.traverse(node => {
    if (node.geometry && !disposed.has(node.geometry)) { node.geometry.dispose(); disposed.add(node.geometry); }
    for (const material of [].concat(node.material ?? [])) {
      if (disposed.has(material)) continue;
      for (const value of Object.values(material)) if (value?.isTexture && !disposed.has(value)) {
        value.dispose(); disposed.add(value);
      }
      material.dispose(); disposed.add(material);
    }
  });
}

function fit(preserveDirection = false) {
  if (!root) return;
  const box = new THREE.Box3().setFromObject(root);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const span = Math.max(size.x, size.y, size.z, 1);
  const halfFov = Math.atan(Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * Math.min(1, camera.aspect));
  const distance = span / (2 * Math.tan(halfFov)) * 1.45;
  const direction = preserveDirection ? camera.position.clone().sub(controls.target).normalize() : new THREE.Vector3(0.9, 0.6, 1).normalize();
  camera.position.copy(center).add(direction.multiplyScalar(distance));
  camera.near = Math.max(0.01, span / 1000);
  camera.far = Math.max(100, span * 100);
  camera.updateProjectionMatrix();
  controls.target.copy(center);
  controls.minDistance = span * 0.08;
  controls.maxDistance = span * 20;
  controls.update();
  render();
}

function showModel(object, name, info, local = false) {
  const box = new THREE.Box3().setFromObject(object);
  if (box.isEmpty() || !Number.isFinite(box.getSize(new THREE.Vector3()).length())) throw new Error('模型沒有有效範圍。');
  if (root) { scene.remove(root); disposeModel(root); }
  if (grid) { scene.remove(grid); disposeModel(grid); }
  root = object;
  scene.add(root);
  const size = box.getSize(new THREE.Vector3());
  const center = box.getCenter(new THREE.Vector3());
  const gridSize = Math.max(size.x, size.z, 5) * 2;
  grid = new THREE.GridHelper(gridSize, 20, '#ccd5da', '#dde4e8');
  grid.position.set(center.x, box.min.y - 0.03, center.z);
  scene.add(grid);
  fitButton.disabled = rotateButton.disabled = false;
  stage.dataset.loaded = 'true';
  message.classList.remove('error');
  message.textContent = `${name} · 拖曳旋轉，滾輪縮放。白色部分表示沒有貼圖。`;
  const download = document.getElementById('download-model');
  if (download) download.hidden = local;
  const notices = qualityNotices(info);
  const quality = document.getElementById('viewer-quality');
  quality.hidden = Boolean(info) && !notices.length;
  quality.textContent = info ? `試轉／待核對：${notices.join('；')}。` : '此 GLB 未附品質摘要，請另外核對影像來源與對位。';
  const credits = document.getElementById('viewer-credits');
  credits.replaceChildren();
  for (const source of Array.isArray(info?.attribution) ? info.attribution : []) {
    if (!source || !/^\d+$/.test(source.image_id)) continue;
    const link = document.createElement('a');
    link.href = `https://www.mapillary.com/app/?pKey=${source.image_id}`;
    link.target = '_blank'; link.rel = 'noopener';
    link.textContent = `Mapillary © ${source.author?.username || source.image_id} · CC BY-SA 4.0`;
    credits.append(link, document.createTextNode('　'));
  }
  if (credits.childNodes.length) credits.append(document.createTextNode('影像經立面投影與裁切。'));
  fit();
}

async function loadFile(file) {
  if (!file) return;
  const version = ++requestId;
  message.textContent = '正在開啟 GLB…';
  try {
    const gltf = await loader.parseAsync(await file.arrayBuffer(), '');
    if (version !== requestId) { disposeModel(gltf.scene); return; }
    showModel(gltf.scene, file.name, gltf.asset?.extras?.geotexture, true);
  } catch (error) {
    if (version !== requestId) return;
    message.classList.add('error'); message.textContent = `模型開啟失敗：${error.message}`;
  }
}
document.getElementById('local-model').addEventListener('change', event => loadFile(event.target.files[0]));
fitButton.onclick = () => fit();
rotateButton.onclick = () => {
  const offset = camera.position.clone().sub(controls.target).applyAxisAngle(new THREE.Vector3(0, 1, 0), Math.PI / 2);
  camera.position.copy(controls.target).add(offset);
  controls.update(); render();
};
resize();
if (stage.dataset.assetUrl) {
  const version = ++requestId;
  message.textContent = '正在載入成果模型…';
  try {
    const gltf = await loader.loadAsync(stage.dataset.assetUrl);
    if (version !== requestId) disposeModel(gltf.scene);
    else showModel(gltf.scene, '成果 GLB', gltf.asset?.extras?.geotexture);
  } catch (error) {
    if (version === requestId) { message.classList.add('error'); message.textContent = `模型載入失敗：${error.message}`; }
  }
} else message.textContent = '選擇一份 GLB，在這裡獨立檢查模型與貼圖。';
