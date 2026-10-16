@php
    use App\Support\Format;
    use App\Support\Markdown;
    use Illuminate\Support\Str;

    // Teks bebas (nama user/project/kategori): spasi & baris baru dirapatkan (tidak memecah baris tabel/daftar),
    // lalu di-escape markdown dan dicetak apa adanya. "<" dan ">" ikut di-escape, jadi tidak bisa membentuk tag HTML.
    $text = fn (string $value): string => Markdown::escape(Str::squish($value));
@endphp
<x-mail::message>
# Ringkasan emisi {{ $summary['period_label'] }}

Halo {!! $text($name) !!},

Total emisi yang disetujui pada {{ $summary['period_label'] }}: **{{ Format::co2($summary['total_co2e_kg']) }}**
@if ($summary['change_pct'] !== null)
({{ Format::change($summary['change_pct']) }} dibanding {{ $summary['previous_label'] }}).
@else
(belum ada emisi disetujui pada {{ $summary['previous_label'] }} sebagai pembanding).
@endif

<x-mail::table>
| Project | {{ $summary['period_label'] }} | Perubahan |
| :------ | ------: | ------: |
@foreach ($summary['projects'] as $project)
| {!! $text($project['name']) !!} | {{ Format::co2($project['total_co2e_kg']) }} | {{ $project['change_pct'] === null ? '-' : Format::change($project['change_pct']) }} |
@endforeach
</x-mail::table>
@if ($summary['project_count'] > count($summary['projects']))

Dan {{ $summary['project_count'] - count($summary['projects']) }} project lainnya (lihat dashboard).
@endif

@if ($summary['pending_review_count'] > 0)
**{{ $summary['pending_review_count'] }} entri** menunggu review Anda.

@endif
@if (count($summary['target_alerts']))
**Target {{ $summary['year'] }} yang perlu diperhatikan:**

@foreach ($summary['target_alerts'] as $alert)
- {!! $text($alert['project_name']) !!} · {!! $text($alert['category']) !!}: {{ number_format($alert['percentage_used'], 1, ',', '.') }}% dari target{{ $alert['level'] === 'exceeded' ? ' (melebihi target)' : '' }}
@endforeach
@if ($summary['alert_count'] > count($summary['target_alerts']))
- Dan {{ $summary['alert_count'] - count($summary['target_alerts']) }} target lainnya (lihat dashboard).
@endif

@endif
<x-mail::button :url="$dashboardUrl">
Buka dashboard
</x-mail::button>

Anda menerima email ini sebagai owner project atau admin. Ringkasan bulanan bisa dimatikan di [halaman Profil]({{ $profileUrl }}).
</x-mail::message>
