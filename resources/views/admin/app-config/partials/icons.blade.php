<form method="POST" action="{{ route('admin.app-config.icons') }}" class="icons-config-form">
    @csrf
    @method('PUT')

    <div class="icons-action-bar toolbar d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div class="flex-grow-1 min-w-0">
            <h2 class="h5 mb-1">Icon Configuration</h2>
            <p class="text-muted small mb-0">Manage app, feature, navigation, and drawer icon metadata returned by the app configuration API.</p>
        </div>
        <div class="icons-actions d-flex flex-wrap gap-2 align-items-center">
            <div class="icons-search input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control js-icon-search" placeholder="Search icons by name, key, group, feature, fallback..." aria-label="Search icons">
            </div>
            <span class="badge bg-light text-dark border js-icon-count" data-total-icons="{{ $icons->count() }}">{{ $icons->count() }} icons</span>
            <button class="btn btn-success"><i class="bi bi-save me-1"></i>Save Icons</button>
        </div>
    </div>

    @if($icons->count())
        <div class="accordion icons-accordion" id="iconsAccordion">
            @foreach($icons->groupBy(fn ($icon) => $icon->icon_group ?: 'custom_assets') as $group => $groupIcons)
                @php($groupId = 'icons-group-' . \Illuminate\Support\Str::slug($group ?: 'custom-assets'))
                <div class="accordion-item ac-card mb-3 js-icon-group" data-group="{{ $group }}">
                    <h3 class="accordion-header" id="{{ $groupId }}-heading">
                        <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $groupId }}" aria-expanded="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $groupId }}">
                            <span class="d-flex flex-wrap align-items-center gap-2 w-100">
                                <span><i class="bi bi-collection text-success me-2"></i>{{ \Illuminate\Support\Str::of($group)->replace('_', ' ')->title() }}</span>
                                <span class="badge bg-light text-dark border js-icon-group-count" data-total-icons="{{ $groupIcons->count() }}">{{ $groupIcons->count() }} icons</span>
                                <span class="small text-muted text-break">{{ $group }}</span>
                            </span>
                        </button>
                    </h3>
                    <div id="{{ $groupId }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" aria-labelledby="{{ $groupId }}-heading" data-bs-parent="#iconsAccordion">
                        <div class="accordion-body p-0">
                            <div class="table-responsive icon-table-wrap">
                                <table class="table icon-config-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Icon</th>
                                            <th>Name</th>
                                            <th>Icon URL</th>
                                            <th>Selected URL</th>
                                            <th>Fallback</th>
                                            <th>Feature</th>
                                            <th>Menu</th>
                                            <th>Active</th>
                                            <th>Sort</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($groupIcons as $icon)
                                            <tr class="js-icon-row {{ $icon->is_active ? '' : 'muted-row' }}" data-search="{{ \Illuminate\Support\Str::lower(trim(($icon->icon_name ?? '') . ' ' . ($icon->icon_key ?? '') . ' ' . ($icon->icon_group ?? '') . ' ' . ($icon->feature_key ?? '') . ' ' . ($icon->menu_key ?? '') . ' ' . ($icon->fallback_asset ?? '') . ' ' . ($icon->default_icon ?? ''))) }}">
                                                <td class="icon-identity-cell">
                                                    <div class="d-flex align-items-center gap-2">
                                                        @if($icon->icon_url)
                                                            <img src="{{ $icon->icon_url }}" alt="{{ $icon->icon_name ?: $icon->icon_key }}" class="icon-preview" data-icon-preview="{{ $icon->icon_key }}">
                                                        @else
                                                            <img src="" alt="" class="icon-preview" data-icon-preview="{{ $icon->icon_key }}" style="display:none">
                                                            <span class="avatar-icon icon-preview-empty" data-icon-preview-empty="{{ $icon->icon_key }}"><i class="bi bi-image"></i></span>
                                                        @endif
                                                        <div class="min-w-0">
                                                            <div class="fw-semibold text-truncate" title="{{ $icon->icon_name ?: $icon->icon_key }}">{{ $icon->icon_name ?: $icon->icon_key }}</div>
                                                            <span class="mono-badge d-inline-block text-truncate icon-key-badge" title="{{ $icon->icon_key }}">{{ $icon->icon_key }}</span>
                                                            <div class="text-muted small mt-1 text-truncate" title="{{ $icon->icon_library ?: 'Icon' }} · {{ $icon->default_icon ?: 'No default' }}">{{ $icon->icon_library ?: 'Icon' }} · {{ $icon->default_icon ?: 'No default' }}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><input class="form-control form-control-sm js-dirty icon-input" name="icons[{{ $icon->icon_key }}][icon_name]" value="{{ $icon->icon_name }}" title="{{ $icon->icon_name }}"></td>
                                                <td>
                                                    <div class="input-group input-group-sm icon-url-group">
                                                        <input class="form-control js-dirty js-icon-url icon-input" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" name="icons[{{ $icon->icon_key }}][icon_url]" value="{{ $icon->icon_url }}" placeholder="https://..." title="{{ $icon->icon_url }}">
                                                        <button type="button" class="btn btn-outline-secondary js-icon-upload" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" title="Upload icon"><i class="bi bi-upload"></i></button>
                                                        <input type="file" class="d-none js-icon-upload-file" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" accept="image/*,.svg">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm icon-url-group">
                                                        <input class="form-control js-dirty js-icon-url icon-input" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" name="icons[{{ $icon->icon_key }}][selected_icon_url]" value="{{ $icon->selected_icon_url }}" placeholder="https://..." title="{{ $icon->selected_icon_url }}">
                                                        <button type="button" class="btn btn-outline-secondary js-icon-upload" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" title="Upload selected icon"><i class="bi bi-upload"></i></button>
                                                        <input type="file" class="d-none js-icon-upload-file" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" accept="image/*,.svg">
                                                    </div>
                                                </td>
                                                <td><input class="form-control form-control-sm js-dirty icon-input" name="icons[{{ $icon->icon_key }}][fallback_asset]" value="{{ $icon->fallback_asset }}" placeholder="assets/icon.png" title="{{ $icon->fallback_asset }}"></td>
                                                <td><input class="form-control form-control-sm js-dirty icon-input icon-short-input" name="icons[{{ $icon->icon_key }}][feature_key]" value="{{ $icon->feature_key }}" title="{{ $icon->feature_key }}"></td>
                                                <td><input class="form-control form-control-sm js-dirty icon-input icon-short-input" name="icons[{{ $icon->icon_key }}][menu_key]" value="{{ $icon->menu_key }}" title="{{ $icon->menu_key }}"></td>
                                                <td>
                                                    <div class="form-check form-switch compact-switch">
                                                        <input type="checkbox" class="form-check-input switch js-dirty" name="icons[{{ $icon->icon_key }}][is_active]" value="1" @checked($icon->is_active)>
                                                    </div>
                                                </td>
                                                <td><input type="number" min="0" class="form-control form-control-sm js-dirty icon-sort-input" name="icons[{{ $icon->icon_key }}][sort_order]" value="{{ $icon->sort_order }}"></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="empty-state d-none js-icon-no-results">
            <i class="bi bi-search fs-3 d-block mb-2"></i>
            <h3 class="h6">No icons match your search</h3>
            <p class="mb-0">Try a different name, key, group, feature, or fallback path.</p>
        </div>
    @else
        <div class="card ac-card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="bi bi-image fs-3 d-block mb-2"></i>
                    <h3 class="h6">No icon assets configured</h3>
                    <p class="mb-0">Run the app configuration icon catalog migration/seeder to populate configurable icons for this app instance.</p>
                </div>
            </div>
        </div>
    @endif
</form>

@push('styles')
<style>
.icons-action-bar{position:sticky;top:72px;z-index:30;background:#fff}.icons-actions{min-width:0}.icons-search{min-width:280px;width:min(480px,42vw)}.icons-accordion .accordion-button{padding:.8rem 1rem}.icon-table-wrap{max-width:100%;overflow-x:auto;overscroll-behavior-x:contain}.icon-config-table{min-width:1420px;table-layout:fixed}.icon-config-table thead th{position:sticky;top:0;z-index:5;padding:.55rem .65rem}.icon-config-table td{padding:.45rem .65rem}.icon-config-table th:nth-child(1),.icon-config-table td:nth-child(1){width:260px}.icon-config-table th:nth-child(2),.icon-config-table td:nth-child(2){width:190px}.icon-config-table th:nth-child(3),.icon-config-table td:nth-child(3),.icon-config-table th:nth-child(4),.icon-config-table td:nth-child(4){width:270px}.icon-config-table th:nth-child(5),.icon-config-table td:nth-child(5){width:190px}.icon-config-table th:nth-child(6),.icon-config-table td:nth-child(6),.icon-config-table th:nth-child(7),.icon-config-table td:nth-child(7){width:145px}.icon-config-table th:nth-child(8),.icon-config-table td:nth-child(8){width:82px}.icon-config-table th:nth-child(9),.icon-config-table td:nth-child(9){width:92px}.icon-preview,.icon-preview-empty{width:34px;height:34px;min-width:34px;border-radius:10px;object-fit:contain}.icon-preview{border:1px solid var(--ac-border);background:#fff;padding:4px}.icon-key-badge{max-width:180px}.icon-input{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.icon-url-group .btn{flex:0 0 auto}.icon-short-input{max-width:135px}.icon-sort-input{max-width:78px}.compact-switch{min-height:0}.min-w-0{min-width:0}.js-icon-row.d-none{display:none!important}@media(max-width:768px){.icons-action-bar{position:static}.icons-actions,.icons-search{width:100%}.icons-search{min-width:0}.icons-actions .btn{width:100%}.icon-config-table{min-width:1100px}.icon-config-table td{padding:.4rem .5rem}}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
    const searchInput=document.querySelector('.js-icon-search');
    if(!searchInput) return;
    const noResults=document.querySelector('.js-icon-no-results');
    const totalBadge=document.querySelector('.js-icon-count');
    const filterIcons=()=>{
        const term=searchInput.value.trim().toLowerCase();
        let visibleTotal=0;
        document.querySelectorAll('.js-icon-group').forEach((group,index)=>{
            let visibleInGroup=0;
            group.querySelectorAll('.js-icon-row').forEach(row=>{
                const show=!term || (row.dataset.search||'').includes(term);
                row.classList.toggle('d-none',!show);
                if(show) visibleInGroup++;
            });
            group.classList.toggle('d-none',visibleInGroup===0);
            const count=group.querySelector('.js-icon-group-count');
            if(count) count.textContent=visibleInGroup+' icon'+(visibleInGroup===1?'':'s');
            visibleTotal+=visibleInGroup;
            const collapse=group.querySelector('.accordion-collapse');
            const button=group.querySelector('.accordion-button');
            if(term && visibleInGroup>0 && collapse && button){
                collapse.classList.add('show');
                button.classList.remove('collapsed');
                button.setAttribute('aria-expanded','true');
            }else if(!term && collapse && button && index>0){
                collapse.classList.remove('show');
                button.classList.add('collapsed');
                button.setAttribute('aria-expanded','false');
            }
        });
        if(totalBadge){
            const total=totalBadge.dataset.totalIcons||visibleTotal;
            totalBadge.textContent=term ? (visibleTotal+' of '+total+' icons') : (total+' icons');
        }
        noResults?.classList.toggle('d-none',visibleTotal!==0);
    };
    searchInput.addEventListener('input',filterIcons);
});
</script>
@endpush
