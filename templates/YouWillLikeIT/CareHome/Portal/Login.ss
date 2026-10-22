<% include YouWillLikeIT\\CareHome\\PortalHead %>
<div class="mx-auto mt-4 max-w-sm" x-data="pinPad(4, 6)" @keydown.window="onKey">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-700 text-white shadow">
            <svg aria-hidden="true" class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M12 13.5c-1.2-1.3-3.5-.6-3.5 1.2 0 1.6 2.1 2.9 3.5 3.8 1.4-.9 3.5-2.2 3.5-3.8 0-1.8-2.3-2.5-3.5-1.2Z"/></svg>
        </div>
        <h1 class="text-2xl font-bold">$Title</h1>
        <p class="mt-1 text-slate-600"><%t CareHomePortal.ENTER_PIN "Unesite svoj PIN" %></p>
    </div>

    <form x-ref="form" method="post" action="$LoginAction" class="ch-card p-5" autocomplete="off">
        <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
        <input type="hidden" name="pin" x-bind:value="pin">

        <div class="mb-6 flex h-6 items-center justify-center gap-3" aria-live="polite">
            <template x-for="i in max" x-bind:key="i">
                <span class="h-4 w-4 rounded-full transition" x-bind:class="i <= pin.length ? 'bg-brand-700 scale-110' : 'bg-slate-200'"></span>
            </template>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <button type="button" class="ch-key" @click="press(1)">1</button>
            <button type="button" class="ch-key" @click="press(2)">2</button>
            <button type="button" class="ch-key" @click="press(3)">3</button>
            <button type="button" class="ch-key" @click="press(4)">4</button>
            <button type="button" class="ch-key" @click="press(5)">5</button>
            <button type="button" class="ch-key" @click="press(6)">6</button>
            <button type="button" class="ch-key" @click="press(7)">7</button>
            <button type="button" class="ch-key" @click="press(8)">8</button>
            <button type="button" class="ch-key" @click="press(9)">9</button>
            <button type="button" class="ch-key text-base text-slate-500" @click="clear()"><%t CareHomePortal.CLEAR "Obriši" %></button>
            <button type="button" class="ch-key" @click="press(0)">0</button>
            <button type="button" class="ch-key" @click="back()" aria-label="<%t CareHomePortal.BACKSPACE "Obriši zadnju cifru" %>">
                <svg aria-hidden="true" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6h11v12H9l-6-6 6-6Z"/><path d="m13 10 4 4m0-4-4 4"/></svg>
            </button>
        </div>

        <button type="submit" class="ch-btn-primary mt-5 w-full text-lg" x-bind:disabled="!ready" x-bind:class="ready ? '' : 'opacity-50'">
            <%t CareHomePortal.LOGIN "Prijava" %>
        </button>
    </form>
    <noscript><p class="mt-4 text-center text-rose-700"><%t CareHomePortal.NOJS "Za prijavu je potreban JavaScript." %></p></noscript>
</div>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
