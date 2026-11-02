@props(['url'])
{{-- Ikon Helpdesk seperti halaman login. PNG karena banyak klien email tidak menampilkan SVG. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/icon.png') }}" class="logo" width="48" height="48" alt="{{ trim(strip_tags($slot)) }}">
</a>
</td>
</tr>
