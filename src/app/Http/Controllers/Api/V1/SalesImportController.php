<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportIssueLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesImportRequest;
use App\Http\Resources\SalesImportResource;
use App\Models\SalesImport;
use App\Services\SalesImport\BossOrderImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesImportController extends Controller
{
    /**
     * 取込の履歴（新しい順）。
     */
    public function index(): AnonymousResourceCollection
    {
        $imports = SalesImport::query()
            ->withCount(['issues as warnings_count' => fn (Builder $query) => $query->where('level', ImportIssueLevel::Warning)])
            ->latest('id')
            ->paginate(20);

        return SalesImportResource::collection($imports);
    }

    /**
     * 取込1件の結果と、エラー・警告の一覧。
     */
    public function show(SalesImport $salesImport): SalesImportResource
    {
        return new SalesImportResource($salesImport->load('issues')->loadCount(['issues as warnings_count' => fn (Builder $query) => $query->where('level', ImportIssueLevel::Warning)]));
    }

    /**
     * BOSS受注実績のCSVを取り込む。ファイル全体を取り込めなかった場合も、失敗の記録を 201 で返す。
     */
    public function store(StoreSalesImportRequest $request, BossOrderImporter $importer): JsonResponse
    {
        $file = $request->file('file');
        $import = $importer->import($file->getClientOriginalName(), $file->get());

        return $this->show($import)->response()->setStatusCode(201);
    }
}
