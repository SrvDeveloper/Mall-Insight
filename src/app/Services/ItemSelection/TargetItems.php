<?php

namespace App\Services\ItemSelection;

use App\Enums\Channel;
use App\Models\ItemSelection;
use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;

/**
 * 今の対象品番（B-136、K-129・K-130）。対象品番は Amazon・BOSS ごとに選んで確定し、全体の対象品番はその2つを合わせたものとする。
 * Amazon・BOSS が未確定の間は、そのチャネルの売上上位10品番（同順位を含む、候補）を使う。
 *
 * - 全体・Amazon・BOSS を切り替える画面（在庫推移・在庫実績・品番別売上・需要予測など）は、切り替えたものの対象品番（itemNos()）を出す。
 * - チャネルを切り替えない画面（販売目標・季節係数・単価・店舗売上）とイベントの倍率を測る品番は、全体の対象品番（all()）を使う。
 * - 予測精度は、チャネルごとにそのチャネルの対象品番で測る（channelItemNos()）。
 *
 * 1回の処理の中で何度も読むため、読んだ選定と候補を覚えておく。
 */
class TargetItems
{
    /** @var array<string, ItemSelection|null> */
    private array $selections = [];

    /** @var array<string, list<string>> */
    private array $candidates = [];

    public function __construct(private ItemRanker $ranker) {}

    /**
     * チャネルの、品番を読み込んだ確定した選定。未確定なら null。
     */
    public function selection(Channel $channel): ?ItemSelection
    {
        $scope = self::scopeOf($channel);
        if (! array_key_exists($scope->value, $this->selections)) {
            $this->selections[$scope->value] = ItemSelection::current($scope)?->load('items');
        }

        return $this->selections[$scope->value];
    }

    /**
     * チャネルの対象品番を確定していないか（そのチャネルの売上上位の品番を使っているか）。全体は、どちらかのチャネルが未確定なら true。
     */
    public function usesCandidates(TrendScope $scope): bool
    {
        $channel = $scope->channel();
        if ($channel === null) {
            return $this->usesCandidates(TrendScope::Amazon) || $this->usesCandidates(TrendScope::Boss);
        }

        return $this->selection($channel) === null;
    }

    /**
     * その対象の対象品番。チャネルは選定の順（未確定なら売上順位の順）、全体は Amazon、BOSS の順に重なりを除いて並べる。
     *
     * @return list<string>
     */
    public function itemNos(TrendScope $scope): array
    {
        $channel = $scope->channel();
        if ($channel === null) {
            return array_values(array_unique([...$this->itemNos(TrendScope::Amazon), ...$this->itemNos(TrendScope::Boss)]));
        }
        $selection = $this->selection($channel);

        return $selection === null ? $this->candidates($scope) : $selection->items->pluck('item_no')->values()->all();
    }

    /**
     * チャネルの対象品番。
     *
     * @return list<string>
     */
    public function channelItemNos(Channel $channel): array
    {
        return $this->itemNos(self::scopeOf($channel));
    }

    /**
     * 全体の対象品番（Amazon・BOSS の対象品番を合わせたもの）。
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->itemNos(TrendScope::Total);
    }

    /**
     * 画面に添える、全体・Amazon・BOSS ごとの対象品番。
     *
     * @return array<string, list<string>>
     */
    public function lists(): array
    {
        $lists = [];
        foreach (TrendScope::cases() as $scope) {
            $lists[$scope->value] = $this->itemNos($scope);
        }

        return $lists;
    }

    /**
     * 画面に出す対象品番の説明。確定日時は、チャネルは確定した日時、全体は Amazon・BOSS のうち新しい方（どちらも未確定なら null）。
     * 対象品番が1つも無ければ null。
     *
     * @return array{scope: string, confirmed_at: string|null, item_count: int, uses_candidates: bool}|null
     */
    public function meta(TrendScope $scope): ?array
    {
        $itemNos = $this->itemNos($scope);
        if ($itemNos === []) {
            return null;
        }
        $channels = $scope->channel() === null ? [Channel::Amazon, Channel::Boss] : [$scope->channel()];
        $confirmedAt = collect($channels)->map(fn (Channel $channel): ?CarbonImmutable => $this->selection($channel)?->confirmed_at?->toImmutable())->filter()->max();

        return [
            'scope' => $scope->value,
            'confirmed_at' => $confirmedAt?->toIso8601String(),
            'item_count' => count($itemNos),
            'uses_candidates' => $this->usesCandidates($scope),
        ];
    }

    /**
     * チャネルの売上上位の品番（同順位を含む、K-129）。売上順位の順。
     *
     * @return list<string>
     */
    private function candidates(TrendScope $scope): array
    {
        return $this->candidates[$scope->value] ??= array_values(array_map(
            fn (ItemRank $rank): string => $rank->item->item_no,
            array_filter($this->ranker->rank(CarbonImmutable::today(), $scope)->ranks, fn (ItemRank $rank): bool => $rank->isCandidate()),
        ));
    }

    private static function scopeOf(Channel $channel): TrendScope
    {
        return $channel === Channel::Amazon ? TrendScope::Amazon : TrendScope::Boss;
    }
}
