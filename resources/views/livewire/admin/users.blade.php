<div class="flex flex-col gap-5">
    <div class="overflow-hidden rounded-2xl border border-line bg-white">
        <div class="grid grid-cols-[1fr_180px] gap-3 border-b border-line bg-[#faf8f6] px-[18px] py-3 text-[11px] font-bold uppercase tracking-wide text-faint">
            <div>User</div><div>Role</div>
        </div>
        @foreach ($users as $user)
            <div class="grid grid-cols-[1fr_180px] items-center gap-3 border-b border-line-soft px-[18px] py-3 last:border-0" wire:key="user-{{ $user->id }}">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="h-9 w-9 shrink-0 rounded-[10px]" style="background: {{ $user->avatarGradient() }}"></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-bold">{{ $user->name }}
                            @if ($user->id === auth()->id())<span class="ml-1 text-[11px] font-semibold text-faint">(you)</span>@endif
                        </span>
                        <span class="block truncate text-xs text-faint">{{ $user->email }}</span>
                    </span>
                </div>
                <div>
                    <select wire:change="updateRole({{ $user->id }}, $event.target.value)"
                            @disabled($user->id === auth()->id())
                            class="h-9 w-full rounded-[9px] border border-line bg-white px-2.5 text-[13px] font-semibold outline-none focus:border-koma disabled:opacity-60">
                        @foreach ($roleNames as $role)
                            <option value="{{ $role }}" @selected($user->hasRole($role))>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-line bg-white p-5">
        <h2 class="mb-4 font-display text-base font-extrabold">Capability matrix</h2>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr class="text-left">
                        <th class="border-b border-line py-2.5 pr-4 font-bold">Capability</th>
                        @foreach ($roleNames as $role)
                            <th class="border-b border-line px-3 py-2.5 text-center text-[12px] font-bold">{{ $role }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($capabilities as $label => $rolesGranted)
                        <tr>
                            <td class="border-b border-line-soft py-2.5 pr-4 font-semibold text-[#57524c]">{{ $label }}</td>
                            @foreach ($roleNames as $role)
                                <td class="border-b border-line-soft px-3 py-2.5 text-center">
                                    @if ($rolesGranted[$role])
                                        <span class="text-koma">●</span>
                                    @else
                                        <span class="text-[#dcd4cb]">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
