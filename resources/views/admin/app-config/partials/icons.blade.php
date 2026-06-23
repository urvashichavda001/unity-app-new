<form method="POST" action="{{ route('admin.app-config.icons') }}">
    @csrf
    @method('PUT')

    <div class="toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Icon Configuration</h2>
            <p class="text-muted small mb-0">Manage app, feature, navigation, and drawer icon metadata returned by the app configuration API.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border">{{ $icons->count() }} icons</span>
            <button class="btn btn-success"><i class="bi bi-save me-1"></i>Save Icons</button>
        </div>
    </div>

    @forelse($icons->groupBy(fn ($icon) => $icon->icon_group ?: 'custom_assets') as $group => $groupIcons)
        <div class="card ac-card mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <h3 class="h6 mb-1">{{ \Illuminate\Support\Str::of($group)->replace('_', ' ')->title() }}</h3>
                    <p class="text-muted small mb-0">Configure {{ $groupIcons->count() }} icon{{ $groupIcons->count() === 1 ? '' : 's' }} in this group.</p>
                </div>
                <span class="badge bg-light text-dark border align-self-start">{{ $group }}</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width:210px">Icon</th>
                            <th style="min-width:220px">Name</th>
                            <th style="min-width:260px">Icon URL</th>
                            <th style="min-width:260px">Selected URL</th>
                            <th style="min-width:180px">Fallback</th>
                            <th style="min-width:150px">Feature</th>
                            <th style="min-width:150px">Menu</th>
                            <th>Active</th>
                            <th style="min-width:110px">Sort</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($groupIcons as $icon)
                            <tr class="{{ $icon->is_active ? '' : 'muted-row' }}">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        @if($icon->icon_url)
                                            <img src="{{ $icon->icon_url }}" alt="{{ $icon->icon_name ?: $icon->icon_key }}" class="brand-asset-preview" data-icon-preview="{{ $icon->icon_key }}">
                                        @else
                                            <img src="" alt="" class="brand-asset-preview" data-icon-preview="{{ $icon->icon_key }}" style="display:none">
                                            <span class="avatar-icon" data-icon-preview-empty="{{ $icon->icon_key }}"><i class="bi bi-image"></i></span>
                                        @endif
                                        <div>
                                            <div class="fw-semibold">{{ $icon->icon_name ?: $icon->icon_key }}</div>
                                            <span class="mono-badge">{{ $icon->icon_key }}</span>
                                            <div class="text-muted small mt-1">{{ $icon->icon_library ?: 'Icon' }} · {{ $icon->default_icon ?: 'No default' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][icon_name]" value="{{ $icon->icon_name }}"></td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input class="form-control js-dirty js-icon-url" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" name="icons[{{ $icon->icon_key }}][icon_url]" value="{{ $icon->icon_url }}" placeholder="https://...">
                                        <button type="button" class="btn btn-outline-secondary js-icon-upload" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url"><i class="bi bi-upload"></i></button>
                                        <input type="file" class="d-none js-icon-upload-file" data-icon-key="{{ $icon->icon_key }}" data-target-field="icon_url" accept="image/*,.svg">
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input class="form-control js-dirty js-icon-url" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" name="icons[{{ $icon->icon_key }}][selected_icon_url]" value="{{ $icon->selected_icon_url }}" placeholder="https://...">
                                        <button type="button" class="btn btn-outline-secondary js-icon-upload" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url"><i class="bi bi-upload"></i></button>
                                        <input type="file" class="d-none js-icon-upload-file" data-icon-key="{{ $icon->icon_key }}" data-target-field="selected_icon_url" accept="image/*,.svg">
                                    </div>
                                </td>
                                <td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][fallback_asset]" value="{{ $icon->fallback_asset }}" placeholder="assets/icon.png"></td>
                                <td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][feature_key]" value="{{ $icon->feature_key }}"></td>
                                <td><input class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][menu_key]" value="{{ $icon->menu_key }}"></td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input switch js-dirty" name="icons[{{ $icon->icon_key }}][is_active]" value="1" @checked($icon->is_active)>
                                    </div>
                                </td>
                                <td><input type="number" min="0" class="form-control form-control-sm js-dirty" name="icons[{{ $icon->icon_key }}][sort_order]" value="{{ $icon->sort_order }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card ac-card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="bi bi-image fs-3 d-block mb-2"></i>
                    <h3 class="h6">No icon assets configured</h3>
                    <p class="mb-0">Run the app configuration icon catalog migration/seeder to populate configurable icons for this app instance.</p>
                </div>
            </div>
        </div>
    @endforelse

    @if($icons->count())
        <div class="mt-3">
            <button class="btn btn-success"><i class="bi bi-save me-1"></i>Save Icons</button>
        </div>
    @endif
</form>
