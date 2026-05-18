@extends('admin.layouts.app')

@section('title', 'Campaign Email Templates')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">Campaign Email Templates</h1>
            <div class="text-muted small">Basic visual layouts available in the campaign builder.</div>
        </div>
        <a href="{{ route('admin.campaigns.create') }}" class="btn btn-primary">Create Campaign</a>
    </div>

    <div class="row g-3">
        @foreach ($templates as $template)
            <div class="col-md-4 col-xl-3">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="campaign-template-thumb campaign-template-thumb-{{ $template->template_type }} mb-3">
                            <span></span><span></span><span></span><span></span>
                        </div>
                        <h2 class="h6 mb-1">{{ $template->name }}</h2>
                        <div class="text-muted small">{{ Str::headline($template->template_type) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@push('styles')
<style>
.campaign-template-thumb{height:150px;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;padding:14px;display:grid;gap:8px}.campaign-template-thumb span{display:block;border-radius:8px;background:#dbeafe;border:1px solid #bfdbfe}.campaign-template-thumb-simple_text{grid-template-rows:repeat(4,1fr)}.campaign-template-thumb-single_column{grid-template-rows:2fr 1fr 1fr}.campaign-template-thumb-one_two_column,.campaign-template-thumb-one_two_column_alternate{grid-template-columns:1fr 1fr;grid-template-rows:1fr 1.5fr}.campaign-template-thumb-one_two_column span:first-child,.campaign-template-thumb-one_two_column_alternate span:first-child{grid-column:1/3}.campaign-template-thumb-one_two_one_two_column{grid-template-columns:1fr 1fr;grid-template-rows:.8fr 1fr .8fr 1fr}.campaign-template-thumb-one_two_one_two_column span:first-child,.campaign-template-thumb-one_two_one_two_column span:nth-child(4){grid-column:1/3}.campaign-template-thumb-one_three_column{grid-template-columns:repeat(3,1fr);grid-template-rows:1fr 1.5fr}.campaign-template-thumb-one_three_column span:first-child{grid-column:1/4}.campaign-template-thumb-blank span{display:none}
</style>
@endpush
