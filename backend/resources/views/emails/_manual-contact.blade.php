@if (! empty($contact['phone']) || ! empty($contact['zalo_url']) || ! empty($contact['email']) || ! empty($contact['hours']))
    <p>Kênh liên hệ Quản trị viên:</p>
    <ul>
        @if (! empty($contact['phone']))<li>Điện thoại: {{ $contact['phone'] }}</li>@endif
        @if (! empty($contact['zalo_url']))<li>Zalo: <a href="{{ $contact['zalo_url'] }}">{{ $contact['zalo_url'] }}</a></li>@endif
        @if (! empty($contact['email']))<li>Email: {{ $contact['email'] }}</li>@endif
        @if (! empty($contact['hours']))<li>Giờ hỗ trợ: {{ $contact['hours'] }}</li>@endif
    </ul>
@endif
