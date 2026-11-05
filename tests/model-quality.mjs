import assert from 'node:assert/strict';
import {qualityNotices} from '../public/assets/model-quality.mjs';

assert.deepEqual(qualityNotices(null), []);
assert.deepEqual(qualityNotices({verification_status: 'trial_unverified'}), ['立面尚待核對']);
assert.deepEqual(qualityNotices({wall_quality: {}, engine_quality: {warnings: {}}, attribution: {}}), []);
assert.deepEqual(qualityNotices({wall_quality: [null, {status: 'missing', flags: ['UNVERIFIED']}]}), []);
const engine = {registration: {global: 5}, ground_sources: {default: 5}, warnings: ['GLOBAL_REGISTRATION', 'NO_CLOUD_PLANE']};
const notices = qualityNotices({engine_quality: engine, wall_quality: [{status: 'textured', flags: ['UNVERIFIED', 'REG_GLOBAL', 'SINGLE_VIEW']} ]});
assert.equal(notices.length, 5);
assert.equal(notices.filter(n => n === '採區域整體對位').length, 1);
assert.ok(notices.includes('地面高度採預設估計'));
assert.ok(qualityNotices({engine_quality: {warnings: ['GLOBAL_REGISTRATION']}}).includes('採區域整體對位'));
console.log('PASS model quality: engine warnings / malformed metadata / missing walls');
