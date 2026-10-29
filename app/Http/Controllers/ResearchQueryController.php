<?php

namespace App\Http\Controllers;

use App\Models\ResearchQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResearchQueryController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        abort_unless($status === '' || in_array($status, ['delivered', 'pending', 'in_progress', 'failed'], true), 404);

        $queries = ResearchQuery::query()
            ->where('user_id', $request->user()->id)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('pages.research.index', compact('queries', 'status'));
    }

    public function destroy(Request $request, ResearchQuery $researchQuery): RedirectResponse
    {
        abort_unless($researchQuery->user_id === $request->user()->id, 404);
        $researchQuery->delete();

        return to_route('research.index')->with('status', 'Pesquisa removida do histórico.');
    }
}
