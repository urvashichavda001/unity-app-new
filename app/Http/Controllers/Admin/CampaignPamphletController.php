<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignPamphlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CampaignPamphletController extends Controller
{
    public function index(): View
    {
        $pamphlets = CampaignPamphlet::query()->latest('created_at')->paginate(20);

        return view('admin.campaign_pamphlets.index', compact('pamphlets'));
    }

    public function create(): View
    {
        return view('admin.campaign_pamphlets.form', [
            'pamphlet' => new CampaignPamphlet(['status' => CampaignPamphlet::STATUS_ACTIVE]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['created_by'] = optional($request->user('admin'))->id;
        $data['updated_by'] = optional($request->user('admin'))->id;
        $data = $this->handleImageUpload($request, $data);

        $pamphlet = CampaignPamphlet::query()->create($data);

        return redirect()->route('admin.campaign-pamphlets.edit', $pamphlet)->with('success', 'Pamphlet created successfully.');
    }

    public function edit(CampaignPamphlet $pamphlet): View
    {
        return view('admin.campaign_pamphlets.form', [
            'pamphlet' => $pamphlet,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, CampaignPamphlet $pamphlet): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['updated_by'] = optional($request->user('admin'))->id;
        $data = $this->handleImageUpload($request, $data);
        $pamphlet->update($data);

        return redirect()->route('admin.campaign-pamphlets.edit', $pamphlet)->with('success', 'Pamphlet updated successfully.');
    }

    public function destroy(CampaignPamphlet $pamphlet): RedirectResponse
    {
        $pamphlet->update(['status' => CampaignPamphlet::STATUS_INACTIVE]);

        return redirect()->route('admin.campaign-pamphlets.index')->with('success', 'Pamphlet deactivated successfully.');
    }

    public function selectList(): JsonResponse
    {
        $pamphlets = CampaignPamphlet::query()
            ->where('status', CampaignPamphlet::STATUS_ACTIVE)
            ->orderBy('title')
            ->get()
            ->map(fn (CampaignPamphlet $pamphlet): array => $pamphlet->toSelectArray())
            ->values();

        return response()->json($pamphlets);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'short_message' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:5120'],
            'status' => ['required', Rule::in([CampaignPamphlet::STATUS_ACTIVE, CampaignPamphlet::STATUS_INACTIVE])],
        ]);
    }

    private function handleImageUpload(Request $request, array $data): array
    {
        unset($data['image']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('campaign-pamphlets', 'public');
            $url = Storage::disk('public')->url($path);
            $data['image_url'] = str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : asset($url);
        }

        return $data;
    }
}
