<div x-data="qrScanner" class="ch-no-print"
     data-base="$Link"
     data-err-camera="<%t CareHomePortal.SCAN_ERR_CAMERA "Kamera nije dostupna. Dozvolite pristup kameri u pregledniku." %>"
     data-err-code="<%t CareHomePortal.SCAN_ERR_CODE "Ovo nije QR kod kreveta ovog doma." %>">
    <div class="fixed inset-x-0 bottom-0 z-30 bg-gradient-to-t from-slate-100 via-slate-100/95 to-transparent px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-6">
        <div class="mx-auto max-w-3xl">
            <button type="button" class="ch-btn-primary w-full text-lg shadow-lg" @click="start()">
                <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/></svg>
                <%t CareHomePortal.SCAN "Skeniraj QR kod kreveta" %>
            </button>
        </div>
    </div>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex flex-col bg-slate-950/95 p-4" role="dialog" aria-modal="true">
        <div class="mx-auto flex w-full max-w-md flex-1 flex-col">
            <p class="mb-3 text-center text-lg font-semibold text-white"><%t CareHomePortal.SCAN_HINT "Usmjerite kameru na QR kod na krevetu" %></p>
            <div id="carehome-qr-reader" class="overflow-hidden rounded-2xl bg-black"></div>
            <p x-show="error" x-text="error" class="mt-3 rounded-xl bg-rose-600 p-3 text-center font-medium text-white"></p>
            <button type="button" class="ch-btn-ghost mt-auto w-full" @click="stop()"><%t CareHomePortal.CANCEL "Odustani" %></button>
        </div>
    </div>
</div>
