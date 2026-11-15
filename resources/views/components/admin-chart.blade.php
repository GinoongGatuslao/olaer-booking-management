@props(['id', 'options', 'height' => 320])

{{-- Draws an ApexCharts chart. The wrapper is keyed on the data, so a new period (or new numbers)
     builds a fresh chart, while the 15-second refresh leaves an unchanged chart alone. --}}
<div wire:key="admin-chart-{{ $id }}-{{ md5(json_encode($options)) }}">
    <div
        wire:ignore
        x-data="{ chart: null }"
        x-init="
            const opts = @js($options);
            const dark = document.documentElement.classList.contains('dark');
            const peso = (v) => '₱' + Number(v ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });
            const fmts = {
                int: (v) => Math.round(Number(v ?? 0)).toLocaleString('en-PH'),
                percent: (v) => Number(v ?? 0).toFixed(1) + '%',
                peso: peso,
            };

            opts.chart = Object.assign({ height: {{ (int) $height }}, background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } }, opts.chart || {});
            opts.theme = { mode: dark ? 'dark' : 'light' };
            opts.grid = Object.assign({ borderColor: dark ? '#3f3f46' : '#e4e4e7' }, opts.grid || {});

            if (opts.yFormats) {
                const axes = Array.isArray(opts.yaxis) ? opts.yaxis : [opts.yaxis || {}];
                opts.yaxis = axes.map((axis, i) => Object.assign({}, axis, { labels: { formatter: fmts[opts.yFormats[i] ?? opts.yFormats[0]] } }));
            }

            if (opts.tooltipFormats) {
                const list = opts.tooltipFormats.map((name) => fmts[name]);
                opts.tooltip = { shared: true, intersect: false, y: list.length === 1 ? list[0] : list };
            }

            const draw = () => {
                if (! window.ApexCharts) { setTimeout(draw, 50); return; }
                chart = new window.ApexCharts($refs.canvas, opts);
                chart.render();
            };

            draw();
        "
    >
        <div x-ref="canvas" style="min-height: {{ (int) $height }}px"></div>
    </div>
</div>
