export function qualityNotices(model) {
  const engine = model?.engine_quality;
  const walls = Array.isArray(model?.wall_quality) ? model.wall_quality : [];
  const flags = new Set([
    ...(Array.isArray(engine?.warnings) ? engine.warnings : []),
    ...walls.filter(w => w && w.status !== 'missing').flatMap(w => Array.isArray(w.flags) ? w.flags : []),
  ]);
  const notices = [];
  if (flags.has('UNVERIFIED') || model?.verification_status === 'trial_unverified') notices.push('立面尚待核對');
  if (flags.has('REG_GLOBAL') || flags.has('GLOBAL_REGISTRATION') || engine?.registration?.global > 0) notices.push('採區域整體對位');
  if (flags.has('SINGLE_VIEW')) notices.push('僅有單張影像支援');
  if (flags.has('GROUND_DEFAULT') || flags.has('DEFAULT_GROUND') || engine?.ground_sources?.default > 0) notices.push('地面高度採預設估計');
  if (flags.has('NO_CLOUD_PLANE')) notices.push('缺少點雲牆面佐證');
  return notices;
}
