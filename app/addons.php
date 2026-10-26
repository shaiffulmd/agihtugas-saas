<?php
declare(strict_types=1);
/* Addon berbayar sekolah — dihidupkan / dimatikan oleh pentadbir platform SAHAJA (kolum schools.addons, senarai dipisah koma).
 * Pentadbir sekolah tidak boleh mengubahnya. Rangka daripada koku-multi; UrusTugas belum mempunyai addon (ciri berbayar
 * masa depan ditambah ke SCHOOL_ADDONS dan disemak dengan school_has_addon()). */

/** [kunci => [nama, keterangan, (simpanan), memerlukan addon]] */
const SCHOOL_ADDONS=[];

function school_addons(?array $s=null): array { $s??=school(); return array_values(array_intersect(array_filter(explode(',',(string)($s['addons']??''))),array_keys(SCHOOL_ADDONS))); }
function school_has_addon(string $addon,?array $s=null): bool { return in_array($addon,school_addons($s),true); }
function addons_ready(): bool { static $ok=null; return $ok??=column_exists('schools','addons'); }

/* ---------------- Platform ---------------- */
function platform_addon_panel(array $s): void {
    if(!addons_ready() || !SCHOOL_ADDONS) return; ?>
<section class="panel padded" id="addon"><div class="sch-h"><h3>Addon berbayar</h3></div><ul class="pl-addons">
<?php foreach(SCHOOL_ADDONS as $k=>[$name,$desc,,$need]): $on=school_has_addon($k,$s); ?><li class="pl-addon"><div><strong><?= e($name) ?></strong> <?= $on?'<span class="badge green">Aktif</span>':'<span class="badge">Tidak aktif</span>' ?><small class="muted block"><?= e($desc) ?><?= $need!==''?' Memerlukan addon '.e(SCHOOL_ADDONS[$need][0]).'.':'' ?></small></div>
<form method="post" data-confirm="<?= e(($on?'Matikan':'Aktifkan').' addon '.$name.' untuk '.$s['code'].'?'.($on?' Data kekal.':'')) ?>"><?= csrf_field() ?><input type="hidden" name="school_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="addon" value="<?= e($k) ?>"><input type="hidden" name="state" value="<?= $on?'0':'1' ?>"><button class="btn sm <?= $on?'outline':'primary' ?>" name="action" value="p_addon"><?= $on?'Matikan':'Aktifkan' ?></button></form></li>
<?php endforeach; ?></ul></section><?php
}
function platform_addon_set(array $s,string $addon,bool $on): void {
    if(!isset(SCHOOL_ADDONS[$addon])) throw new DomainException('Addon tidak dikenali.');
    $need=SCHOOL_ADDONS[$addon][3]; if($on && $need!=='' && !school_has_addon($need,$s)) throw new DomainException('Aktifkan addon '.SCHOOL_ADDONS[$need][0].' dahulu.');
    $list=array_values(array_diff(school_addons($s),[$addon])); if($on) $list[]=$addon;
    // Matikan addon induk → addon yang bergantung kepadanya turut dimatikan.
    if(!$on) foreach(SCHOOL_ADDONS as $k=>$a) if($a[3]===$addon && in_array($k,$list,true)){ $list=array_values(array_diff($list,[$k])); platform_log('school_addon_off',(int)$s['id'],$k.' (bergantung pada '.$addon.')'); }
    query('UPDATE schools SET addons=? WHERE id=?',[implode(',',$list),(int)$s['id']]);
    platform_log($on?'school_addon_on':'school_addon_off',(int)$s['id'],$addon);
}

/** Panel Platform → Addon Sekolah: semua sekolah × addon di satu tempat. */
function platform_page_addons(): void {
    ?><div class="page-heading"><div><span class="eyebrow teal">SEKOLAH</span><h1>Addon Sekolah</h1><p class="muted">Hidupkan atau matikan addon berbayar bagi setiap sekolah. Data kekal apabila addon dimatikan. Addon juga boleh diurus di halaman butiran sekolah.</p></div></div><?php
    if(!addons_ready()){ p_notice('Addon belum dipasang pada pangkalan data ini. Jalankan: php bin/upgrade.php','error'); return; }
    if(!SCHOOL_ADDONS){ p_notice('Tiada addon tersedia buat masa ini.'); return; }
    $q=mb_substr(trim(input('q','get')),0,80); $rows=array_values(array_filter(platform_schools($q),fn($s)=>!in_array($s['status'],['diarkib'],true)));
    $count=[]; foreach($rows as $s) foreach(school_addons($s) as $a) $count[$a]=($count[$a]??0)+1; ?>
<section class="stats-grid st-row"><?php foreach(SCHOOL_ADDONS as $k=>[$name]): ?><article class="stat-card"><div class="stat-top"><small><?= e($name) ?></small></div><strong><?= (int)($count[$k]??0) ?></strong><small>sekolah aktif</small></article><?php endforeach; ?></section>
<form method="get" class="form-grid flush"><input type="hidden" name="p" value="addon"><label class="field full">Cari sekolah<input name="q" value="<?= e($q) ?>" placeholder="Kod atau nama"></label></form>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Sekolah</th><th>Pakej</th><?php foreach(SCHOOL_ADDONS as $k=>[$name,,,$need]): ?><th><?= e($name) ?><?= $need!==''?'<small class="cell-sub">perlu '.e(SCHOOL_ADDONS[$need][0]).'</small>':'' ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $s): ?><tr><td><a class="text-link" href="/platform/?p=sekolah&amp;id=<?= (int)$s['id'] ?>#addon"><strong><?= e($s['code']) ?></strong></a><small class="cell-sub"><?= e($s['name']) ?></small></td><td><?= plan_badge(school_plan($s)) ?></td>
<?php foreach(SCHOOL_ADDONS as $k=>[$name,,,$need]): $on=school_has_addon($k,$s); $blocked=!$on && $need!=='' && !school_has_addon($need,$s); ?><td><form method="post" class="pl-addon-cell" data-confirm="<?= e(($on?'Matikan':'Aktifkan').' '.$name.' untuk '.$s['code'].'?') ?>"><?= csrf_field() ?><input type="hidden" name="school_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="addon" value="<?= e($k) ?>"><input type="hidden" name="state" value="<?= $on?'0':'1' ?>"><input type="hidden" name="kembali" value="addon"><?= $on?'<span class="badge green">Aktif</span>':'<span class="badge">Mati</span>' ?><button class="btn sm outline" name="action" value="p_addon"<?= $blocked?' disabled title="Aktifkan '.e(SCHOOL_ADDONS[$need][0]).' dahulu"':'' ?>><?= $on?'Matikan':'Aktifkan' ?></button></form></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?= 2+count(SCHOOL_ADDONS) ?>" class="muted">Tiada sekolah.</td></tr><?php endif; ?></tbody></table></div></section><?php
}
