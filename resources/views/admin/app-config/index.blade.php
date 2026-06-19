@extends('admin.layouts.app')
@section('title', 'App Configuration')
@php
$active = request('tab','overview');
$tabs = [
    'overview'=>['label'=>'Overview','icon'=>'bi-speedometer2'],
    'branding'=>['label'=>'Branding','icon'=>'bi-palette'],
    'labels'=>['label'=>'Labels','icon'=>'bi-tags'],
    'features'=>['label'=>'Features','icon'=>'bi-toggle2-on'],
    'icons'=>['label'=>'Icons','icon'=>'bi-image'],
    'navigation'=>['label'=>'Navigation','icon'=>'bi-menu-button-wide'],
    'widgets'=>['label'=>'Dashboard Widgets','icon'=>'bi-grid-3x3-gap'],
    'social'=>['label'=>'Social Links','icon'=>'bi-share'],
    'membership'=>['label'=>'Membership Labels','icon'=>'bi-person-badge'],
    'api-docs'=>['label'=>'API Docs','icon'=>'bi-file-earmark-code'],
];
$branding = (array) ($branding ?? []);
$colorFields = ['primary_color','primary_dark_color','primary_ultra_light_color','secondary_color','text_primary_color','text_secondary_color','background_color','card_background_color'];
$colorRoles = [
    'primary_color' => ['label'=>'Brand Color','used'=>'buttons, active icons, links, toggles, and FAB.','recommended'=>'#44A268'],
    'primary_dark_color' => ['label'=>'Brand Pressed Color','used'=>'pressed, tap, hover states, and status bar tint.','recommended'=>'#1B5E20'],
    'primary_ultra_light_color' => ['label'=>'Brand Tint Color','used'=>'chips, subtle backgrounds, card accents, empty states, light icon backgrounds, and profile strength tracks.','recommended'=>'#E8F5E9','helper'=>'Use a very light tint of Brand Color. Example: #E8F5E9'],
    'secondary_color' => ['label'=>'Title Text Color','used'=>'screen titles, app bar titles, user names, section headings, dialog titles, drawer text, and profile names.','recommended'=>'#0F172A'],
    'text_primary_color' => ['label'=>'Body Text Color','used'=>'paragraphs, post content, form labels, list descriptions, card body text, settings item text, and chat messages.','recommended'=>'#466186'],
    'text_secondary_color' => ['label'=>'Subtitle Text Color','used'=>'timestamps, hints, subtitles, designations, placeholders, and empty state text.','recommended'=>'#6B7280'],
    'background_color' => ['label'=>'Screen Background','used'=>'the main app screen background behind all content.','recommended'=>'#F5F7FA'],
    'card_background_color' => ['label'=>'Card Background','used'=>'post cards, profile cards, meeting cards, deal cards, and content boxes.','recommended'=>'#FFFFFF'],
];
$brandUploadFields = ['logo_url_light','logo_url_dark','logo_url_splash','app_logo_url','splash_logo_url'];
$brandGroups = [
    'Basic App Info'=>['icon'=>'bi-info-circle','help'=>'Core identity and launch assets shown in the mobile app.','fields'=>['app_name','logo_url_light','logo_url_dark','logo_url_splash','app_logo_url','splash_logo_url']],
    'App Color Roles'=>['icon'=>'bi-droplet-half','help'=>'Control the main colors used across the Greenpreneur mobile app.','fields'=>$colorFields],
    'App Links & Support'=>['icon'=>'bi-link-45deg','help'=>'Store links, website, and support channels displayed to users.','fields'=>['playstore_url','appstore_url','website_url','support_email','support_phone']],
];
$fieldHelp = [
    'app_logo_url'=>'Paste a publicly reachable image URL for the main app logo.',
    'splash_logo_url'=>'Used on splash/loading experiences where supported.',
    'support_email'=>'Customer support email displayed in app help areas.',
    'support_phone'=>'Optional phone/WhatsApp support number.',
];
$menuTypes = ['bottom_nav'=>'Bottom Navigation','plus_menu'=>'Plus Menu','impact_menu'=>'Impact Menu','drawer'=>'Drawer Menu'];
$menuHelp = ['bottom_nav'=>'Mobile bottom tabs','plus_menu'=>'Center action menu','impact_menu'=>'Impact shortcuts','drawer'=>'Side drawer options'];
$methodClass = ['GET'=>'primary','POST'=>'success','PUT'=>'warning text-dark','DELETE'=>'danger'];
$docs = [
['GET','/app/config','No','Public Flutter app config','{}','{"success":true,"data":{"colors":{"primary_color":"#44A268","primary_dark_color":"#1B5E20","primary_ultra_light_color":"#E8F5E9","secondary_color":"#0F172A","text_primary_color":"#466186","text_secondary_color":"#6B7280","background_color":"#F5F7FA","card_background_color":"#FFFFFF"}}}'],
['GET','/admin/app-config','Yes','Fetch full admin config','{}','{"success":true,"data":{"branding":{},"labels":[]}}'],
['PUT','/admin/app-config/branding','Yes','Update branding','{"app_name":"Greenpreneur"}','{"success":true}'],
['PUT','/admin/app-config/colors','Yes','Update colors','{"primary_color":"#2E7D32"}','{"success":true}'],
['PUT','/admin/app-config/icons/{icon_key}','Yes','Update one icon','{"icon_url":null}','{"success":true}'],
['PUT','/admin/app-config/icons','Yes','Bulk update icons','{"icons":{"home_icon":null}}','{"success":true}'],
['PUT','/admin/app-config/labels/{label_key}','Yes','Update one label','{"label_value":"Green Member"}','{"success":true}'],
['PUT','/admin/app-config/labels','Yes','Bulk update labels','{"labels":{"peer":"Green Member"}}','{"success":true}'],
['PUT','/admin/app-config/features/{feature_key}','Yes','Update one feature','{"is_enabled":true}','{"success":true}'],
['PUT','/admin/app-config/features','Yes','Bulk update features','{"features":{"events":true}}','{"success":true}'],
['POST','/admin/app-config/navigation','Yes','Create navigation item','{"menu_type":"bottom_nav","item_key":"feed"}','{"success":true}'],
['PUT','/admin/app-config/navigation/{id}','Yes','Update navigation item','{"display_label":"Feed"}','{"success":true}'],
['DELETE','/admin/app-config/navigation/{id}','Yes','Delete navigation item','{}','{"success":true}'],
['PUT','/admin/app-config/dashboard-widgets/{widget_key}','Yes','Update dashboard widget','{"is_enabled":true}','{"success":true}'],
['PUT','/admin/app-config/social-links/{platform}','Yes','Update social link','{"display_name":"LinkedIn","url":"https://linkedin.com/company/greenpreneur"}','{"success":true}'],
['POST','/admin/app-config/clear-cache','Yes','Clear app config cache','{}','{"success":true}'],
];
$enabledFeatures = $features->where('is_enabled',true)->count();
$disabledFeatures = $features->where('is_enabled',false)->count();
$enabledWidgets = $widgets->filter(fn($w)=>($w->is_enabled ?? $w->is_enable ?? false))->count();
$enabledSocial = $socialLinks->where('is_enabled',true)->filter(fn($s)=>filled($s->url))->count();
$publicApi = 'https://peersunity.com/api/v1/app/config';

if (! function_exists('getReadableTextColor')) {
    function getReadableTextColor($hexColor) {
        if (! $hexColor) {
            return '#0F172A';
        }

        $hex = ltrim(trim((string) $hexColor), '#');
        if (strlen($hex) === 8) {
            $hex = substr($hex, 2);
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#0F172A';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness > 180 ? '#0F172A' : '#FFFFFF';
    }
}

if (! function_exists('isLightColor')) {
    function isLightColor($hexColor) {
        if (! $hexColor) {
            return true;
        }

        $hex = ltrim(trim((string) $hexColor), '#');
        if (strlen($hex) === 8) {
            $hex = substr($hex, 2);
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return true;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness > 180;
    }
}
@endphp
@push('styles')
<style>
.app-config-page{--ac-green:#16834a;--ac-soft:#f4faf7;--ac-border:#dfe8e3;--ac-ink:#173327}.app-config-page .hero{background:linear-gradient(135deg,#0f5132,#1f9d61);border-radius:24px;color:#fff;padding:24px;box-shadow:0 18px 45px rgba(15,81,50,.18)}.app-config-page .hero .btn{border-radius:999px}.ac-tabs{background:#fff;border:1px solid var(--ac-border);border-radius:18px;padding:8px;box-shadow:0 10px 28px rgba(23,51,39,.06)}.ac-tabs .nav-link{border-radius:14px;color:#476254;font-weight:600;padding:.72rem .9rem}.ac-tabs .nav-link.active{background:var(--ac-green);box-shadow:0 8px 18px rgba(22,131,74,.22)}.ac-card{border:1px solid var(--ac-border);border-radius:18px;box-shadow:0 10px 25px rgba(23,51,39,.05)}.ac-card .card-header{background:#fff;border-bottom:1px solid var(--ac-border);border-radius:18px 18px 0 0}.stat-card{transition:.18s ease;overflow:hidden}.stat-card:hover{transform:translateY(-2px);box-shadow:0 16px 35px rgba(23,51,39,.09)}.stat-icon,.avatar-icon{width:42px;height:42px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;background:var(--ac-soft);color:var(--ac-green)}.quick-card{display:block;text-decoration:none;color:inherit;border:1px solid var(--ac-border);border-radius:16px;padding:16px;background:#fff}.quick-card:hover{border-color:var(--ac-green);background:var(--ac-soft)}.form-control,.form-select{border-color:#d7e3dc;border-radius:12px}.form-control:focus,.form-select:focus{border-color:#56b982;box-shadow:0 0 0 .2rem rgba(22,131,74,.12)}.btn{border-radius:12px;font-weight:600}.table thead th{position:sticky;top:0;background:#f8fbf9;z-index:1;color:#385244;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}.table td,.table th{vertical-align:middle}.mono-badge{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f3f6f4;border:1px solid #dfe8e3;border-radius:999px;padding:.25rem .55rem;font-size:.78rem}.switch{width:2.65rem;height:1.35rem}.changed{background:#fff8e6!important;box-shadow:inset 4px 0 #ffc107}.code-block{background:#0d1f17;color:#d9fbe7;border-radius:14px;padding:14px;white-space:pre-wrap;font-size:.78rem}.brand-preview{background:linear-gradient(180deg,#fff,#f5fbf7);border:1px solid var(--ac-border);border-radius:20px;padding:18px;position:sticky;top:16px}.color-chip{width:42px;border-radius:12px;border:1px solid #d7e3dc}.empty-state{border:1px dashed #bfd3c7;border-radius:16px;padding:24px;text-align:center;color:#6b7f73;background:#fbfdfc}.toolbar{background:#fff;border:1px solid var(--ac-border);border-radius:18px;padding:14px}.labels-table-wrapper{max-height:calc(100vh - 390px);overflow-y:auto;overflow-x:auto;padding-bottom:90px;border-radius:12px}.labels-table-wrapper table{width:100%;border-collapse:separate;border-spacing:0}.labels-table-wrapper thead th{position:sticky;top:0;z-index:20;background:#f8fafc}.labels-table tbody tr:last-child td{padding-bottom:18px}.labels-footer-actions{position:sticky;bottom:0;z-index:30;background:#fff;padding:12px 16px;border-top:1px solid #e5e7eb}.accordion-button{border-radius:16px!important;font-weight:700}.accordion-item{border:1px solid var(--ac-border);border-radius:18px!important;overflow:hidden}.muted-row{opacity:.62}.toast-lite{position:fixed;right:20px;bottom:20px;background:#173327;color:#fff;border-radius:14px;padding:12px 16px;box-shadow:0 12px 30px rgba(0,0,0,.18);z-index:1080}.membership-preview li{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #edf3ef}.brand-asset-preview{max-height:60px;max-width:180px;object-fit:contain;border:1px solid var(--ac-border);border-radius:10px;padding:4px;background:#fff;margin-top:8px}.brand-color-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:600;line-height:1;margin:4px;min-height:26px;box-shadow:0 1px 2px rgba(15,23,42,.08)}.brand-upload-group .btn{border-top-left-radius:0;border-bottom-left-radius:0}@media(max-width:768px){.app-config-page .hero{padding:18px}.ac-tabs{overflow-x:auto;flex-wrap:nowrap!important}.ac-tabs .nav-link{white-space:nowrap}.labels-table-wrapper{max-height:calc(100vh - 300px)}}
</style>
@endpush
@section('content')
<div class="app-config-page">
    <div class="hero mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div><div class="small text-white-50 mb-1">Mobile App Settings</div><h1 class="h3 mb-2">App Configuration</h1><p class="mb-0 text-white-50">Manage branding, labels, menus, widgets, membership labels, and social links for the greenpreneur app instance.</p></div>
        <div class="d-flex flex-wrap gap-2"><form method="POST" action="{{ route('admin.app-config.clear-cache') }}">@csrf<input type="hidden" name="tab" value="{{ $active }}"><button class="btn btn-light"><i class="bi bi-arrow-clockwise me-1"></i>Clear App Config Cache</button></form><a href="{{ $publicApi }}" target="_blank" class="btn btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Test Public Config API</a></div>
    </div>
    @if(session('success'))<div class="alert alert-success rounded-4"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger rounded-4"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    <ul class="nav nav-pills ac-tabs flex-wrap gap-1 mb-4">@foreach($tabs as $key=>$tab)<li class="nav-item"><a class="nav-link {{ $active===$key?'active':'' }}" href="?tab={{ $key }}"><i class="bi {{ $tab['icon'] }} me-1"></i>{{ $tab['label'] }}</a></li>@endforeach</ul>

@if($active==='overview')
    <div class="row g-3 mb-4">
        @foreach([['bi-phone','App Name',$branding['app_name'] ?? 'Greenpreneur'],['bi-hash','App Slug',$app->slug],['bi-tags','Total Labels',$labels->count()],['bi-toggle-on','Enabled Features',$enabledFeatures],['bi-toggle-off','Disabled Features',$disabledFeatures],['bi-menu-button','Navigation Items Count',$navigation->count()],['bi-grid','Enabled Dashboard Widgets',$enabledWidgets],['bi-share','Social Links Count',$socialLinks->count()],['bi-clock-history','Last Updated',$lastUpdated ?: '—']] as $s)
        <div class="col-sm-6 col-xl-4"><div class="card ac-card stat-card h-100"><div class="card-body d-flex gap-3"><span class="stat-icon"><i class="bi {{ $s[0] }}"></i></span><div><div class="text-muted small">{{ $s[1] }}</div><div class="fs-5 fw-bold text-dark">{{ $s[2] }}</div></div></div></div></div>
        @endforeach
    </div>
    <div class="row g-3 mb-4"><div class="col-lg-8"><div class="card ac-card h-100"><div class="card-body"><h2 class="h5 mb-1">Quick Access</h2><p class="text-muted small mb-3">Jump directly to the area you want to explain or update.</p><div class="row g-3">@foreach(['branding'=>'Edit Branding','labels'=>'Manage Labels','features'=>'Manage Features','navigation'=>'Manage Navigation','widgets'=>'Manage Widgets','social'=>'Manage Social Links','membership'=>'Manage Membership Labels','api-docs'=>'View API Docs'] as $key=>$label)<div class="col-md-6"><a class="quick-card" href="?tab={{ $key }}"><i class="bi {{ $tabs[$key]['icon'] }} me-2 text-success"></i>{{ $label }}</a></div>@endforeach</div></div></div></div><div class="col-lg-4"><div class="card ac-card"><div class="card-body"><h2 class="h6">Config Health</h2>@foreach([['Branding configured',filled($branding['app_name'] ?? null)],['Labels available',$labels->count()>0],['Navigation menu ready',$navigation->count()>0],['Social links configured',$enabledSocial>0],['Membership labels configured',$membershipLabels->count()>0]] as $h)<div class="d-flex justify-content-between py-1"><span>{{ $h[0] }}</span><span class="badge {{ $h[1]?'bg-success':'bg-danger' }}">{{ $h[1]?'Ready':'Needs review' }}</span></div>@endforeach</div></div></div></div>
@endif

@if($active==='branding')
<form method="POST" action="{{ route('admin.app-config.branding') }}">
    @csrf @method('PUT')
    <div class="row g-4">
        <div class="col-xl-8">
            @foreach($brandGroups as $title=>$group)
            <div class="card ac-card mb-4">
                <div class="card-header"><h2 class="h5 mb-1"><i class="bi {{ $group['icon'] }} text-success me-2"></i>{{ $title }}</h2><p class="text-muted small mb-0">{{ $group['help'] }}</p></div>
                <div class="card-body row g-3">
                    @foreach($group['fields'] as $field)
                        @if(isset($colorRoles[$field]))
                            @php($role = $colorRoles[$field])
                            @php($value = old($field, $branding[$field] ?? $role['recommended']))
                            <div class="col-md-6"><div class="card h-100 border rounded-4 shadow-sm"><div class="card-body">
                                <div class="d-flex justify-content-between gap-2 mb-2"><div><label class="form-label fw-bold mb-1" for="{{ $field }}">{{ $role['label'] }}</label><div class="small text-muted"><code>{{ $field }}</code></div></div><span class="brand-color-chip" data-preview-chip="{{ $field }}" style="background-color:{{ $value }};color:{{ getReadableTextColor($value) }};border:{{ isLightColor($value) ? '1px solid #D1D5DB' : '1px solid transparent' }}">{{ $role['label'] }}</span></div>
                                <p class="small text-muted mb-2"><strong>Used for</strong> {{ $role['used'] }}</p>
                                <div class="input-group"><input type="color" class="form-control form-control-color color-chip js-color" value="{{ $value }}" data-target="{{ $field }}"><input id="{{ $field }}" name="{{ $field }}" value="{{ $value }}" class="form-control js-brand-input js-color-text" placeholder="{{ $role['recommended'] }}" required pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$"></div>
                                <div class="form-text">Recommended: {{ $role['recommended'] }}@if(isset($role['helper']))<br>{{ $role['helper'] }}@endif</div>
                            </div></div></div>
                        @else
                            <div class="col-md-6"><label class="form-label fw-semibold" title="{{ $field }}">{{ ucwords(str_replace('_',' ', $field)) }}</label><div class="input-group @if(in_array($field,$brandUploadFields,true)) brand-upload-group @endif"><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $branding[$field] ?? ($field==='app_name'?'Greenpreneur':'')) }}" class="form-control js-brand-input" placeholder="{{ $field }}" @if(in_array($field,$brandUploadFields,true)) type="url" @endif>@if(in_array($field,$brandUploadFields,true))<button type="button" class="btn btn-outline-success js-brand-asset-upload" data-field="{{ $field }}"><i class="bi bi-cloud-upload me-1"></i>Upload</button><input type="file" class="d-none js-brand-asset-file" data-field="{{ $field }}" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml">@endif</div>@if(in_array($field,$brandUploadFields,true))<img src="{{ old($field, $branding[$field] ?? '') }}" class="brand-asset-preview" data-preview-for="{{ $field }}" style="display:{{ filled(old($field, $branding[$field] ?? '')) ? 'block' : 'none' }}" onerror="this.style.display='none'">@endif<div class="form-text">{{ $fieldHelp[$field] ?? 'Used by the app configuration API.' }}</div></div>
                        @endif
                    @endforeach
                </div>
            </div>
            @endforeach
            <div class="sticky-bottom bg-white border rounded-4 p-3 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-save me-1"></i>Save Branding</button><button type="button" class="btn btn-outline-secondary" id="resetPreview">Reset color preview</button></div>
        </div>
        <div class="col-xl-4"><div class="brand-preview" data-preview-shell style="background:{{ old('background_color', $branding['background_color'] ?? '#F5F7FA') }}"><h2 class="h5">Live Brand Preview</h2><p class="text-muted small">Visual sanity check using the 8 app color roles.</p><img data-logo-preview src="{{ $branding['app_logo_url'] ?? '' }}" class="rounded-4 border mb-3" style="width:82px;height:82px;object-fit:contain" onerror="this.style.display='none'"><div class="p-3 rounded-4 mb-3" data-preview-card style="background:{{ old('card_background_color', $branding['card_background_color'] ?? '#FFFFFF') }}"><div class="fw-bold" data-preview-name style="color:{{ old('secondary_color', $branding['secondary_color'] ?? '#0F172A') }}">{{ $branding['app_name'] ?? 'Greenpreneur' }}</div><div class="small" data-preview-subtitle style="color:{{ old('text_secondary_color', $branding['text_secondary_color'] ?? '#6B7280') }}">Sample mobile configuration card</div><p class="small mt-2 mb-2" data-preview-body style="color:{{ old('text_primary_color', $branding['text_primary_color'] ?? '#466186') }}">This preview shows how your app colors will look.</p><span class="badge rounded-pill mb-3" data-preview-badge style="background:{{ old('primary_ultra_light_color', $branding['primary_ultra_light_color'] ?? '#E8F5E9') }};color:{{ old('primary_color', $branding['primary_color'] ?? '#44A268') }}">Eco Member</span><div class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-sm text-white" data-preview-button style="background:{{ old('primary_color', $branding['primary_color'] ?? '#44A268') }}">Sample Button</button><button type="button" class="btn btn-sm text-white" data-preview-pressed style="background:{{ old('primary_dark_color', $branding['primary_dark_color'] ?? '#1B5E20') }}">Pressed</button></div></div><div class="d-flex gap-2 flex-wrap">@foreach(['primary_color'=>'brand','primary_dark_color'=>'pressed','primary_ultra_light_color'=>'tint','secondary_color'=>'title','text_primary_color'=>'body','text_secondary_color'=>'subtitle','background_color'=>'screen bg','card_background_color'=>'card bg'] as $field=>$label)@php($chipColor = old($field, $branding[$field] ?? ($colorRoles[$field]['recommended'] ?? '#198754')))<span class="brand-color-chip" data-preview-chip="{{ $field }}" style="background-color:{{ $chipColor }};color:{{ getReadableTextColor($chipColor) }};border:{{ isLightColor($chipColor) ? '1px solid #D1D5DB' : '1px solid transparent' }}">{{ $label }}</span>@endforeach</div></div></div>
    </div>
</form>
@endif
@if($active==='labels')
<form method="POST" action="{{ route('admin.app-config.labels') }}" class="card ac-card">@csrf @method('PUT')<div class="card-header"><div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1">Labels</h2><p class="text-muted small mb-0">Edit text shown in the app without changing code. <span class="badge bg-light text-dark border">{{ $labels->count() }} total</span></p></div><button class="btn btn-success"><i class="bi bi-save me-1"></i>Save All Changed Labels</button></div></div><div class="card-body"><div class="toolbar row g-2 mb-3"><div class="col-md-5"><input class="form-control js-search" data-target="#labelsTable" placeholder="Search labels by key, value, group, or description"></div><div class="col-md-4"><select class="form-select js-filter" data-target="#labelsTable" data-col="group"><option value="">All groups</option>@foreach($labels->pluck('group_name')->filter()->unique()->sort() as $group)<option value="{{ $group }}">{{ $group }}</option>@endforeach</select></div><div class="col-md-3"><select class="form-select js-filter" data-target="#labelsTable" data-col="active"><option value="">All statuses</option><option value="1">Active</option><option value="0">Inactive</option></select></div></div><div class="table-responsive labels-table-wrapper"><table id="labelsTable" class="table align-middle mb-0 labels-table"><thead><tr><th>Label Key</th><th>Label Value</th><th>Group Name</th><th>Description</th><th>Active</th><th>Action</th></tr></thead><tbody>@forelse($labels as $l)<tr data-group="{{ $l->group_name }}" data-active="{{ $l->is_active ? '1':'0' }}"><td><span class="mono-badge">{{ $l->label_key }}</span></td><td><input class="form-control form-control-sm js-dirty" name="labels[{{ $l->label_key }}][label_value]" value="{{ $l->label_value }}"></td><td><input class="form-control form-control-sm js-dirty" name="labels[{{ $l->label_key }}][group_name]" value="{{ $l->group_name }}"></td><td><input class="form-control form-control-sm js-dirty" name="labels[{{ $l->label_key }}][description]" value="{{ $l->description }}"></td><td><div class="form-check form-switch"><input type="checkbox" class="form-check-input switch js-dirty" name="labels[{{ $l->label_key }}][is_active]" value="1" @checked($l->is_active)></div></td><td><button class="btn btn-sm btn-outline-success">Save</button></td></tr>@empty<tr><td colspan="6"><div class="empty-state">No labels found.</div></td></tr>@endforelse</tbody></table></div></div><div class="card-footer labels-footer-actions"><button class="btn btn-success">Bulk Save Labels</button><button type="reset" class="btn btn-outline-secondary ms-2">Discard changes</button><span class="small text-muted ms-2 unsaved-count"></span></div></form>
@endif


@if($active==='icons')
@php($iconGroups=['bottom_navigation'=>'Bottom Navigation','highlights_grid'=>'Highlights Grid','plus_menu'=>'Plus Menu','impact_dashboard'=>'Impact Dashboard','drawer_menu'=>'Drawer Menu','custom_assets'=>'Custom Assets'])
<form method="POST" action="{{ route('admin.app-config.icons') }}">@csrf @method('PUT')<div class="toolbar d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5 mb-1">Mobile Icon Catalog</h2><p class="text-muted small mb-0">Manage Iconsax, Material, custom asset, and remote URL icon mappings used by Flutter.</p></div><button class="btn btn-success"><i class="bi bi-save me-1"></i>Bulk Save Icons</button></div>@foreach($iconGroups as $groupKey=>$groupLabel)@php($groupIcons=$icons->where('icon_group',$groupKey)->sortBy('sort_order'))<div class="card ac-card mb-4"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><div><h3 class="h5 mb-1">{{ $groupLabel }}</h3><p class="text-muted small mb-0"><span class="badge bg-light text-dark border">{{ $groupIcons->count() }} icons</span></p></div><button class="btn btn-sm btn-outline-success">Save {{ $groupLabel }}</button></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Sort</th><th>Icon Key</th><th>Icon Name</th><th>Source Type</th><th>Library</th><th>Default Icon</th><th>Selected Icon</th><th style="min-width:260px">Remote Icon URL</th><th style="min-width:260px">Selected Remote URL</th><th>Fallback Asset</th><th>Feature Key</th><th>Menu Key</th><th>Preview</th><th>Active</th><th>Action</th></tr></thead><tbody>@forelse($groupIcons as $icon)<tr><td><input type="number" min="0" class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][sort_order]" value="{{ $icon->sort_order }}" style="width:80px"></td><td><span class="mono-badge">{{ $icon->icon_key }}</span></td><td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][icon_name]" value="{{ $icon->icon_name }}"></td><td><span class="badge bg-light text-dark border">{{ $icon->source_type }}</span></td><td><input class="form-control form-control-sm" value="{{ $icon->icon_library }}" readonly></td><td><input class="form-control form-control-sm" value="{{ $icon->default_icon }}" readonly></td><td><input class="form-control form-control-sm" value="{{ $icon->selected_icon }}" readonly></td><td><div class="input-group input-group-sm"><input class="form-control js-dirty js-icon-url" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" name="icons[{{ $icon->icon_key }}][icon_url]" value="{{ $icon->icon_url }}" placeholder="Remote icon URL"><button type="button" class="btn btn-outline-success js-icon-upload" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url"><i class="bi bi-cloud-upload"></i></button><input type="file" class="d-none js-icon-upload-file" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml"></div></td><td><div class="input-group input-group-sm"><input class="form-control js-dirty js-icon-url" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" name="icons[{{ $icon->icon_key }}][selected_icon_url]" value="{{ $icon->selected_icon_url }}" placeholder="Selected remote URL"><button type="button" class="btn btn-outline-success js-icon-upload" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url"><i class="bi bi-cloud-upload"></i></button><input type="file" class="d-none js-icon-upload-file" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml"></div></td><td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][fallback_asset]" value="{{ $icon->fallback_asset }}"></td><td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][feature_key]" value="{{ $icon->feature_key }}"></td><td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][menu_key]" value="{{ $icon->menu_key }}"></td><td>@if($icon->icon_url)<img src="{{ $icon->icon_url }}" data-icon-preview="{{ $icon->icon_key }}" style="width:32px;height:32px;object-fit:contain" onerror="this.style.display='none'">@else<span class="text-muted small" data-icon-preview-empty="{{ $icon->icon_key }}">fallback</span><img src="" data-icon-preview="{{ $icon->icon_key }}" style="width:32px;height:32px;object-fit:contain;display:none" onerror="this.style.display='none'">@endif</td><td><div class="form-check form-switch"><input type="checkbox" class="form-check-input switch js-dirty" name="icons[{{ $icon->icon_key }}][is_active]" value="1" @checked($icon->is_active)></div></td><td><button class="btn btn-sm btn-outline-success">Save</button></td></tr>@empty<tr><td colspan="15"><div class="empty-state">No icons configured for {{ $groupLabel }}. Run migrations to seed the Greenpreneur icon catalog.</div></td></tr>@endforelse</tbody></table></div></div>@endforeach<div class="sticky-bottom bg-white border rounded-4 p-3"><button class="btn btn-success"><i class="bi bi-save me-1"></i>Bulk Save Icons</button></div></form>
@endif

@if($active==='features')
<form method="POST" action="{{ route('admin.app-config.features') }}">@csrf @method('PUT')<div class="toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">Feature Visibility</h2><p class="text-muted small mb-0">Control which app modules are visible in Flutter app.</p></div><div class="d-flex gap-2"><span class="badge bg-success align-self-center">{{ $enabledFeatures }} enabled</span><span class="badge bg-danger align-self-center">{{ $disabledFeatures }} disabled</span><button class="btn btn-success">Save All Feature Changes</button></div></div><div class="row g-3 mb-3"><div class="col-md-8"><input class="form-control js-search-card" data-target=".feature-card" placeholder="Search features"></div><div class="col-md-4"><select class="form-select js-card-status"><option value="">All statuses</option><option value="1">Enabled</option><option value="0">Disabled</option></select></div></div><div class="row g-3">@forelse($features as $f)<div class="col-lg-6 feature-card" data-active="{{ $f->is_enabled?'1':'0' }}"><div class="card ac-card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><div><h3 class="h6 mb-1">{{ $f->feature_name }}</h3><span class="mono-badge">{{ $f->feature_key }}</span></div><span class="badge {{ $f->is_enabled?'bg-success':'bg-secondary' }} align-self-start">{{ $f->is_enabled?'Enabled':'Disabled' }}</span></div><div class="row g-2 align-items-end mt-3"><div class="col-6"><label class="form-label small">Visibility</label><div class="form-check form-switch"><input type="checkbox" class="form-check-input switch js-dirty" name="features[{{ $f->feature_key }}][is_enabled]" value="1" @checked($f->is_enabled)></div></div><div class="col-6"><label class="form-label small">Sort Order</label><input type="number" min="0" class="form-control form-control-sm js-dirty" name="features[{{ $f->feature_key }}][sort_order]" value="{{ $f->sort_order }}"></div></div><button class="btn btn-sm btn-outline-success mt-3">Save Feature</button></div></div></div>@empty<div class="col-12"><div class="empty-state">No features configured.</div></div>@endforelse</div><div class="mt-3"><button class="btn btn-success">Bulk Save Features</button></div></form>
@endif

@if($active==='navigation')
<div class="card ac-card mb-4"><div class="card-header"><h2 class="h5 mb-1">Add New Navigation Item</h2><p class="text-muted small mb-0">Create menu entries for bottom tabs, action menus, impact shortcuts, or drawer links.</p></div><div class="card-body"><form method="POST" action="{{ route('admin.app-config.navigation.store') }}" class="row g-3">@csrf <div class="col-md-3"><label class="form-label">Menu Type</label><select name="menu_type" class="form-select">@foreach($menuTypes as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>@foreach(['item_key'=>'Item Key','label_key'=>'Label Key','display_label'=>'Display Label','icon'=>'Icon','route_name'=>'Route Name','feature_key'=>'Feature Key'] as $f=>$label)<div class="col-md-3"><label class="form-label">{{ $label }}</label><input name="{{ $f }}" class="form-control" placeholder="{{ $f }}" @if($f==='display_label'||$f==='item_key') required @endif><div class="form-text">{{ $f==='feature_key'?'Optional gate feature key.':'Used by app navigation rendering.' }}</div></div>@endforeach<div class="col-md-2"><label class="form-label">Sort Order</label><input name="sort_order" type="number" value="0" class="form-control"></div><div class="col-md-2 d-flex align-items-end"><div class="form-check form-switch mb-2"><input name="is_enabled" value="1" checked type="checkbox" class="form-check-input switch"><label class="form-check-label">Enabled</label></div></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-success w-100"><i class="bi bi-plus-circle me-1"></i>Add Item</button></div></form></div></div>
<div class="row g-3 mb-3">@foreach($menuTypes as $key=>$label)<div class="col-md-3"><div class="quick-card"><div class="fw-semibold">{{ $label }}</div><div class="small text-muted">{{ $menuHelp[$key] }}</div></div></div>@endforeach</div>
<div class="accordion" id="navGroups">@foreach($menuTypes as $menu=>$label)@php($items=$navigation->where('menu_type',$menu))<div class="accordion-item mb-3 js-nav-group" data-menu-type="{{ $menu }}"><h2 class="accordion-header"><div class="accordion-button {{ $loop->first?'':'collapsed' }}" role="button" data-bs-toggle="collapse" data-bs-target="#nav-{{ $menu }}"><span class="d-flex flex-wrap align-items-center gap-2 flex-grow-1 text-start"><span><i class="bi bi-list-nested text-success me-2"></i>{{ $label }}</span><span class="badge bg-light text-dark border">{{ $items->count() }} items</span><span class="small text-muted">{{ $menuHelp[$menu] }}</span><span class="badge bg-warning text-dark d-none js-nav-unsaved">Unsaved</span></span><button type="button" class="btn btn-sm btn-outline-success me-3 js-save-nav-group" data-menu-type="{{ $menu }}" data-save-url="{{ route('admin.app-config.navigation.group-update',$menu) }}"><i class="bi bi-save me-1"></i>Save {{ $label }}</button></div></h2><div id="nav-{{ $menu }}" class="accordion-collapse collapse {{ $loop->first?'show':'' }}" data-bs-parent="#navGroups"><div class="accordion-body p-0">@if($items->count())<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Sort</th><th>Item Key</th><th>Display Label</th><th>Icon</th><th>Route</th><th>Feature</th><th>Enabled</th><th>Actions</th></tr></thead><tbody>@foreach($items as $n)<tr class="js-nav-row"><form method="POST" action="{{ route('admin.app-config.navigation.update',$n->id) }}">@csrf @method('PUT')<input type="hidden" name="menu_type" data-field="menu_type" value="{{ $menu }}"><input type="hidden" name="id" data-field="id" value="{{ $n->id }}"><td><input name="sort_order" data-field="sort_order" value="{{ $n->sort_order }}" class="form-control form-control-sm js-dirty" type="number"></td><td><input name="item_key" data-field="item_key" value="{{ $n->item_key }}" class="form-control form-control-sm js-dirty"><input type="hidden" name="label_key" data-field="label_key" value="{{ $n->label_key }}"></td><td><input name="display_label" data-field="display_label" value="{{ $n->display_label }}" class="form-control form-control-sm js-dirty"></td><td><input name="icon" data-field="icon" value="{{ $n->icon }}" class="form-control form-control-sm js-dirty"></td><td><input name="route_name" data-field="route_name" value="{{ $n->route_name }}" class="form-control form-control-sm js-dirty"></td><td><input name="feature_key" data-field="feature_key" value="{{ $n->feature_key }}" class="form-control form-control-sm js-dirty"></td><td><div class="form-check form-switch"><input type="checkbox" class="form-check-input switch js-dirty" name="is_enabled" data-field="is_enabled" value="1" @checked($n->is_enabled)></div></td><td class="text-nowrap"><button class="btn btn-sm btn-outline-success">Save</button></form><form method="POST" class="d-inline" action="{{ route('admin.app-config.navigation.destroy',$n->id) }}" onsubmit="return confirm('Delete this navigation item?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr>@endforeach</tbody></table></div>@else<div class="empty-state m-3">No {{ strtolower($label) }} items yet.</div>@endif</div></div></div>@endforeach</div>
@endif
@if($active==='widgets')
<form method="POST" action="{{ route('admin.app-config.widgets') }}">@csrf @method('PUT')<div class="toolbar d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5 mb-1">Dashboard Widgets</h2><p class="text-muted small mb-0">Control which sections appear on the app dashboard and in what order.</p></div><button class="btn btn-success">Save All Widgets</button></div><div class="row g-3">@forelse($widgets as $w)<div class="col-lg-6"><div class="card ac-card h-100"><div class="card-body"><div class="d-flex justify-content-between"><div><h3 class="h6 mb-1">{{ $w->widget_name }}</h3><span class="mono-badge">{{ $w->widget_key }}</span></div><span class="avatar-icon"><i class="bi bi-grid"></i></span></div><p class="text-muted small mt-3 mb-0">Preview order controls where this section appears on the dashboard.</p><div class="row g-2 align-items-end mt-2"><div class="col-6"><label class="form-label small">Enabled</label><div class="form-check form-switch"><input type="checkbox" class="form-check-input switch js-dirty" name="widgets[{{ $w->widget_key }}][is_enabled]" value="1" @checked($w->is_enabled ?? $w->is_enable ?? false)></div></div><div class="col-6"><label class="form-label small">Sort Order</label><input type="number" min="0" class="form-control form-control-sm js-dirty" name="widgets[{{ $w->widget_key }}][sort_order]" value="{{ $w->sort_order }}"></div></div><button class="btn btn-sm btn-outline-success mt-3">Save Widget</button></div></div></div>@empty<div class="col-12"><div class="empty-state">No dashboard widgets configured.</div></div>@endforelse</div><div class="mt-3"><button class="btn btn-success">Save All Widgets</button></div></form>
@endif

@if($active==='social')
<form method="POST" action="{{ route('admin.app-config.social') }}" class="card ac-card">@csrf @method('PUT')<div class="card-header d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1">Social Links</h2><p class="text-muted small mb-0">Manage the social platforms and website links shown in the mobile app.</p></div><button class="btn btn-success">Save All Social Links</button></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Platform</th><th>Display Name</th><th style="min-width:280px">URL</th><th>Icon</th><th>Enabled</th><th>Sort Order</th><th>Action</th></tr></thead><tbody>@forelse($socialLinks as $s)<tr class="{{ $s->is_enabled ? '' : 'muted-row' }}"><td><span class="avatar-icon me-2"><i class="bi {{ $s->icon ?: 'bi-link-45deg' }}"></i></span><span class="mono-badge">{{ $s->platform }}</span></td><td><input class="form-control form-control-sm js-dirty" name="social_links[{{ $s->platform }}][display_name]" value="{{ $s->display_name }}"></td><td><input class="form-control form-control-sm js-dirty" name="social_links[{{ $s->platform }}][url]" value="{{ $s->url }}" placeholder="Not configured"></td><td><input class="form-control form-control-sm js-dirty" name="social_links[{{ $s->platform }}][icon]" value="{{ $s->icon }}"></td><td><div class="form-check form-switch"><input type="checkbox" class="form-check-input switch js-dirty" name="social_links[{{ $s->platform }}][is_enabled]" value="1" @checked($s->is_enabled)></div></td><td><input type="number" class="form-control form-control-sm js-dirty" name="social_links[{{ $s->platform }}][sort_order]" value="{{ $s->sort_order }}"></td><td><button class="btn btn-sm btn-outline-success">Save</button></td></tr>@empty<tr><td colspan="7"><div class="empty-state">No social links configured.</div></td></tr>@endforelse</tbody></table></div><div class="card-footer bg-white"><button class="btn btn-success">Save All Social Links</button></div></form>
@endif

@if($active==='membership')
<form method="POST" action="{{ route('admin.app-config.membership-labels') }}">@csrf @method('PUT')<div class="row g-4"><div class="col-lg-8"><div class="card ac-card"><div class="card-header"><h2 class="h5 mb-1">Membership Labels</h2><p class="text-muted small mb-0">Manage the display names for membership types shown in the app without changing backend membership keys.</p></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Membership Key</th><th>Display Label</th><th>Description</th><th>Action</th></tr></thead><tbody>@forelse($membershipLabels as $m)<tr><td><span class="mono-badge">{{ $m->membership_key }}</span></td><td><input class="form-control form-control-sm js-dirty" name="membership_labels[{{ $m->membership_key }}][display_label]" value="{{ $m->display_label }}"></td><td><input class="form-control form-control-sm js-dirty" name="membership_labels[{{ $m->membership_key }}][description]" value="{{ $m->description }}"></td><td><button class="btn btn-sm btn-outline-success">Save</button></td></tr>@empty<tr><td colspan="4"><div class="empty-state">No membership labels configured.</div></td></tr>@endforelse</tbody></table></div><div class="card-footer bg-white"><button class="btn btn-success">Save Membership Labels</button></div></div></div><div class="col-lg-4"><div class="card ac-card"><div class="card-body"><h3 class="h6">Example Preview</h3><p class="text-muted small">Canonical mapping for client demos and QA checks.</p><ul class="list-unstyled membership-preview mb-0"><li><code>free_peer</code><strong>Free Member</strong></li><li><code>unity_peer</code><strong>Green Member</strong></li><li><code>only_unity_peer</code><strong>Eco Member</strong></li><li><code>chartered_peer</code><strong>Premium Green Member</strong></li><li><code>charter_investor</code><strong>Green Investor</strong></li></ul></div></div></div></div></form>
@endif

@if($active==='api-docs')
<div class="card ac-card mb-4"><div class="card-body"><div class="row g-3"><div class="col-md-4"><div class="text-muted small">Base URL</div><code>https://peersunity.com/api/v1</code></div><div class="col-md-4"><div class="text-muted small">Public API</div><span class="badge bg-primary me-1">GET</span><code>/app/config</code></div><div class="col-md-4"><div class="text-muted small">Admin headers</div><button class="btn btn-sm btn-outline-secondary copy-btn" data-copy="Authorization: Bearer ADMIN_TOKEN&#10;Accept: application/json&#10;Content-Type: application/json">Copy Headers</button></div></div></div></div>
@foreach(['Public API'=>array_slice($docs,0,1),'Admin APIs'=>array_slice($docs,1)] as $group=>$items)<h2 class="h5 mt-4">{{ $group }}</h2><div class="row g-3">@foreach($items as $d)<div class="col-xl-6"><div class="card ac-card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2 mb-2"><div><span class="badge bg-{{ $methodClass[$d[0]] ?? 'secondary' }} me-2">{{ $d[0] }}</span><code>{{ $d[1] }}</code></div><span class="badge {{ $d[2]==='Yes'?'bg-dark':'bg-light text-dark border' }}">Auth: {{ $d[2] }}</span></div><p class="text-muted small">{{ $d[3] }}</p><div class="row g-2"><div class="col-md-6"><div class="small fw-semibold mb-1">Request example</div><pre class="code-block">{{ $d[4] }}</pre></div><div class="col-md-6"><div class="small fw-semibold mb-1">Response example</div><pre class="code-block">{{ $d[5] }}</pre></div></div><button class="btn btn-sm btn-outline-secondary copy-btn mt-2" data-copy="{{ $d[1] }}">Copy endpoint</button></div></div></div>@endforeach</div>@endforeach
<div class="card ac-card mt-4"><div class="card-header"><h3 class="h6 mb-0">Simplified Flutter Color Roles</h3></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Role</th><th>API key</th><th>Use for</th></tr></thead><tbody><tr><td>Brand Color</td><td><code>primary_color</code></td><td>buttons, active icons, links, toggles</td></tr><tr><td>Brand Pressed Color</td><td><code>primary_dark_color</code></td><td>pressed/hover/status bar</td></tr><tr><td>Brand Tint Color</td><td><code>primary_ultra_light_color</code></td><td>chips, light backgrounds, icon backgrounds</td></tr><tr><td>Title Text Color</td><td><code>secondary_color</code></td><td>headings, user names, app titles</td></tr><tr><td>Body Text Color</td><td><code>text_primary_color</code></td><td>paragraphs, descriptions, labels</td></tr><tr><td>Subtitle Text Color</td><td><code>text_secondary_color</code></td><td>hints, timestamps, designations</td></tr><tr><td>Screen Background</td><td><code>background_color</code></td><td>app screen background</td></tr><tr><td>Card Background</td><td><code>card_background_color</code></td><td>cards and content boxes</td></tr></tbody></table></div></div><div class="alert alert-success rounded-4 mt-4"><h3 class="h6"><i class="bi bi-phone me-1"></i>Flutter Integration Notes</h3><ul class="mb-0"><li>Call <code>/app/config</code> on splash.</li><li>Cache the response and refresh after app updates or admin changes.</li><li>Only these 8 color roles should be used by Flutter; do not use removed/old color keys.</li><li>Use labels dynamically for all Unity/Greenpreneur naming.</li><li>Use features and widget flags for visibility.</li><li>Read <code>data.icons</code>; prefer <code>icon_url</code>/<code>selected_icon_url</code>, then custom fallback assets, Iconsax defaults, or Material defaults.</li><li>If an icon feature key is disabled, hide the related menu or action.</li><li>Do not hardcode Unity labels in Flutter screens.</li></ul></div>
@endif
</div>
@endsection
@push('scripts')
<script>
const toast=(msg)=>{const t=document.createElement('div');t.className='toast-lite';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),1600)};
document.querySelectorAll('.copy-btn').forEach(b=>b.addEventListener('click',e=>{e.preventDefault();navigator.clipboard.writeText(b.dataset.copy||'');const old=b.innerHTML;b.innerHTML='<i class="bi bi-check2 me-1"></i>Copied';toast('Copied to clipboard');setTimeout(()=>b.innerHTML=old,1200)}));
document.querySelectorAll('.js-search').forEach(i=>i.addEventListener('input',()=>{document.querySelectorAll(i.dataset.target+' tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(i.value.toLowerCase())?'':'none')}));
document.querySelectorAll('.js-filter').forEach(f=>f.addEventListener('change',()=>{const rows=document.querySelectorAll(f.dataset.target+' tbody tr');document.querySelectorAll('.js-filter[data-target="'+f.dataset.target+'"]').forEach(()=>{});rows.forEach(r=>{let show=true;document.querySelectorAll('.js-filter[data-target="'+f.dataset.target+'"]').forEach(x=>{if(x.value && (r.dataset[x.dataset.col]||'')!==x.value) show=false});r.style.display=show?'':'none'})}));
document.querySelectorAll('.js-search-card').forEach(i=>i.addEventListener('input',()=>document.querySelectorAll(i.dataset.target).forEach(c=>c.style.display=c.innerText.toLowerCase().includes(i.value.toLowerCase())?'':'none')));
document.querySelectorAll('.js-card-status').forEach(s=>s.addEventListener('change',()=>document.querySelectorAll('.feature-card').forEach(c=>c.style.display=!s.value||c.dataset.active===s.value?'':'none')));
document.querySelectorAll('.js-dirty').forEach(el=>el.addEventListener('change',()=>{const row=el.closest('tr,.card');if(row) row.classList.add('changed');document.querySelectorAll('.unsaved-count').forEach(x=>x.textContent='Unsaved changes detected')}));
const normalizeHexForPreview=(hex)=>{if(!hex)return null;let value=String(hex).trim().replace(/^#/,'');if(value.length===8)value=value.substring(2);return /^[0-9a-fA-F]{6}$/.test(value)?value:null};
const previewBrightness=(hex)=>{const value=normalizeHexForPreview(hex);if(!value)return 255;const r=parseInt(value.substring(0,2),16),g=parseInt(value.substring(2,4),16),b=parseInt(value.substring(4,6),16);return ((r*299)+(g*587)+(b*114))/1000};
const getReadableTextColor=(hex)=>previewBrightness(hex)>180?'#0F172A':'#FFFFFF';
const isLightColor=(hex)=>previewBrightness(hex)>180;
document.querySelectorAll('.js-color').forEach(c=>c.addEventListener('input',()=>{const target=document.getElementById(c.dataset.target);if(target) target.value=c.value;document.querySelectorAll('[data-preview-chip="'+c.dataset.target+'"]').forEach(chip=>{chip.style.backgroundColor=c.value;chip.style.color=getReadableTextColor(c.value);chip.style.border=isLightColor(c.value)?'1px solid #D1D5DB':'1px solid transparent'});if(c.dataset.target==='primary_color'){const btn=document.querySelector('[data-preview-button]');if(btn) btn.style.background=c.value;const badge=document.querySelector('[data-preview-badge]');if(badge) badge.style.color=c.value}if(c.dataset.target==='primary_dark_color'){const btn=document.querySelector('[data-preview-pressed]');if(btn) btn.style.background=c.value}if(c.dataset.target==='primary_ultra_light_color'){const badge=document.querySelector('[data-preview-badge]');if(badge) badge.style.background=c.value}if(c.dataset.target==='secondary_color'){const name=document.querySelector('[data-preview-name]');if(name) name.style.color=c.value}if(c.dataset.target==='text_primary_color'){const body=document.querySelector('[data-preview-body]');if(body) body.style.color=c.value}if(c.dataset.target==='text_secondary_color'){const subtitle=document.querySelector('[data-preview-subtitle]');if(subtitle) subtitle.style.color=c.value}if(c.dataset.target==='background_color'){const shell=document.querySelector('[data-preview-shell]');if(shell) shell.style.background=c.value}if(c.dataset.target==='card_background_color'){const card=document.querySelector('[data-preview-card]');if(card) card.style.background=c.value}}));
document.querySelectorAll('.js-color-text').forEach(input=>input.addEventListener('input',()=>{const picker=document.querySelector('.js-color[data-target="'+input.id+'"]');if(picker && /^#([A-Fa-f0-9]{6})$/.test(input.value)) picker.value=input.value;picker?.dispatchEvent(new Event('input',{bubbles:true}));}));
document.querySelectorAll('.js-brand-input').forEach(i=>i.addEventListener('input',()=>{if(i.id==='app_name'){const n=document.querySelector('[data-preview-name]');if(n) n.textContent=i.value||'Greenpreneur'}if(i.id==='app_logo_url'){const img=document.querySelector('[data-logo-preview]');if(img){img.src=i.value;img.style.display=i.value?'block':'none'}}}));

const brandUploadUrl='{{ route('admin.app-config.upload-brand-asset') }}';
const csrfToken=document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
document.querySelectorAll('.js-brand-asset-upload').forEach(button=>button.addEventListener('click',()=>{
    document.querySelector('.js-brand-asset-file[data-field="'+button.dataset.field+'"]')?.click();
}));
document.querySelectorAll('.js-brand-asset-file').forEach(fileInput=>fileInput.addEventListener('change',async()=>{
    const file=fileInput.files?.[0];
    if(!file) return;
    const field=fileInput.dataset.field;
    const button=document.querySelector('.js-brand-asset-upload[data-field="'+field+'"]');
    const oldHtml=button?.innerHTML;
    const formData=new FormData();
    formData.append('field',field);
    formData.append('file',file);
    if(button){button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Uploading';}
    try{
        const response=await fetch(brandUploadUrl,{method:'POST',headers:{'X-CSRF-TOKEN':csrfToken,'Accept':'application/json'},body:formData});
        const payload=await response.json().catch(()=>({success:false,message:'Upload failed.'}));
        if(!response.ok || !payload.success) throw new Error(payload.message || 'Upload failed.');
        const input=document.getElementById(field);
        if(input){input.value=payload.data.url;input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));}
        const preview=document.querySelector('[data-preview-for="'+field+'"]');
        if(preview){preview.src=payload.data.url;preview.style.display='block';}
        toast(payload.message || 'Brand asset uploaded successfully.');
    }catch(error){
        toast(error.message || 'Upload failed.');
    }finally{
        fileInput.value='';
        if(button){button.disabled=false;button.innerHTML=oldHtml;}
    }
}));


const iconUploadUrl='{{ route('admin.app-config.icons.upload') }}';
document.querySelectorAll('.js-icon-upload').forEach(button=>button.addEventListener('click',()=>{
    document.querySelector('.js-icon-upload-file[data-icon-key="'+button.dataset.iconKey+'"][data-target-field="'+button.dataset.targetField+'"]')?.click();
}));
document.querySelectorAll('.js-icon-upload-file').forEach(fileInput=>fileInput.addEventListener('change',async()=>{
    const file=fileInput.files?.[0];
    if(!file) return;
    const button=document.querySelector('.js-icon-upload[data-icon-key="'+fileInput.dataset.iconKey+'"][data-target-field="'+fileInput.dataset.targetField+'"]');
    const oldHtml=button?.innerHTML;
    const formData=new FormData();
    formData.append('icon_key',fileInput.dataset.iconKey);
    formData.append('target_field',fileInput.dataset.targetField);
    formData.append('file',file);
    if(button){button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm"></span>';}
    try{
        const response=await fetch(iconUploadUrl,{method:'POST',headers:{'X-CSRF-TOKEN':csrfToken,'Accept':'application/json'},body:formData});
        const payload=await response.json().catch(()=>({success:false,message:'Upload failed.'}));
        if(!response.ok || !payload.success) throw new Error(payload.message || 'Upload failed.');
        const input=document.querySelector('.js-icon-url[data-icon-key="'+payload.data.icon_key+'"][data-target-field="'+payload.data.target_field+'"]');
        if(input){input.value=payload.data.url;input.dispatchEvent(new Event('change',{bubbles:true}));}
        if(payload.data.target_field==='icon_url'){
            const preview=document.querySelector('[data-icon-preview="'+payload.data.icon_key+'"]');
            const empty=document.querySelector('[data-icon-preview-empty="'+payload.data.icon_key+'"]');
            if(empty) empty.style.display='none';
            if(preview){preview.src=payload.data.url;preview.style.display='inline-block';}
        }
        toast(payload.message || 'Icon uploaded successfully.');
    }catch(error){toast(error.message || 'Upload failed.');}
    finally{fileInput.value='';if(button){button.disabled=false;button.innerHTML=oldHtml;}}
}));


document.querySelectorAll('.js-nav-group .js-dirty').forEach(input=>input.addEventListener('change',()=>{
    const group=input.closest('.js-nav-group');
    group?.querySelector('.js-nav-unsaved')?.classList.remove('d-none');
}));
document.querySelectorAll('.js-save-nav-group').forEach(button=>button.addEventListener('click',async(event)=>{
    event.preventDefault();
    event.stopPropagation();
    const group=document.querySelector('.js-nav-group[data-menu-type="'+button.dataset.menuType+'"]');
    if(!group) return;
    const oldHtml=button.innerHTML;
    const items=[...group.querySelectorAll('.js-nav-row')].map(row=>{
        const item={};
        row.querySelectorAll('[data-field]').forEach(field=>{
            const key=field.dataset.field;
            if(key==='menu_type') return;
            if(key==='is_enabled') item[key]=field.checked;
            else if(key==='sort_order') item[key]=field.value===''?0:Number(field.value);
            else item[key]=field.value;
        });
        return item;
    });
    button.disabled=true;
    button.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    try{
        const response=await fetch(button.dataset.saveUrl,{method:'PUT',headers:{'X-CSRF-TOKEN':csrfToken,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({items})});
        const payload=await response.json().catch(()=>({success:false,message:'Navigation group save failed.'}));
        if(!response.ok || !payload.success){
            const details=payload.errors?Object.values(payload.errors).flat().join(' '):'';
            throw new Error(details || payload.message || 'Navigation group save failed.');
        }
        group.querySelectorAll('.changed').forEach(row=>row.classList.remove('changed'));
        group.querySelector('.js-nav-unsaved')?.classList.add('d-none');
        toast(payload.message || 'Navigation group saved successfully.');
    }catch(error){
        toast(error.message || 'Navigation group save failed.');
    }finally{
        button.disabled=false;
        button.innerHTML=oldHtml;
    }
}));

document.getElementById('resetPreview')?.addEventListener('click',()=>document.querySelectorAll('.js-color').forEach(c=>c.dispatchEvent(new Event('input'))));
</script>
@endpush
