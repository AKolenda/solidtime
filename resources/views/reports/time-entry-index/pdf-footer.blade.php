<!DOCTYPE html>
<html lang="en">
    <head>
        <style>
            @font-face {
                font-family: 'Outfit';
                src: url('outfit.ttf');
            }
            * { box-sizing: border-box; }
            html, body { margin: 0; padding: 0; width: 100%; font-family: 'Outfit', Arial, sans-serif; color: #18181b; }
            .page-number {
                margin-top: 4px;
                padding: 0 0.35in;
                color: #71717a;
                font-size: 8px;
                text-align: right;
            }
            .shop-footer { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 1.6fr); margin: 0 0.35in; border-top: 1px solid #18181b; border-bottom: 1px solid #d4d4d8; background: #f8f8f8; }
            .shop-footer > div { padding: 5px 8px; border-right: 1px solid #d4d4d8; }
            .shop-footer > div:last-child { border-right: 0; }
            .shop-footer span { display: block; margin-bottom: 2px; color: #71717a; font-size: 7px; font-weight: 600; text-transform: uppercase; white-space: nowrap; }
            .shop-footer strong { display: block; font-size: 10px; }
        </style>
    </head>
    <body>
    @if($shopReport && $shopReport->secondsPerPiece !== null)
        @php($quarterHours = fn (int|float $seconds): string => number_format(round(($seconds / 3600) * 4) / 4, 2).' h')
        @php($pieceHours = fn (int|float $seconds): string => number_format($seconds / 3600, 2).' h')
        @php($turningOperation = collect($shopReport->operations)->firstWhere('name', 'Turning'))
        @php($millingOperation = collect($shopReport->operations)->firstWhere('name', 'Milling'))
        @php($setupLabel = $shopReport->runningSeconds !== null ? 'Setup' : 'Total')
        <div class="shop-footer">
            <div><span>Total Running</span><strong>{{ $shopReport->runningSeconds !== null ? $quarterHours($shopReport->runningSeconds) : '-' }}</strong></div>
            <div><span>Turning {{ $setupLabel }}</span><strong>{{ $quarterHours($turningOperation['setup_seconds'] ?? 0) }}</strong></div>
            <div><span>Milling {{ $setupLabel }}</span><strong>{{ $quarterHours($millingOperation['setup_seconds'] ?? 0) }}</strong></div>
            <div><span>{{ $shopReport->runningSeconds !== null ? 'Combined Run Avg / Piece' : 'Combined Avg / Piece' }}</span><strong>{{ $pieceHours($shopReport->secondsPerPiece) }}</strong></div>
        </div>
    @endif
    <div class="page-number">Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>
    </body>
</html>
