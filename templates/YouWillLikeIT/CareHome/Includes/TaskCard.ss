<%-- Scope: one row from PortalController::taskRows() --%>
<li id="task-$Task.ID" class="ch-card scroll-mt-24 overflow-hidden <% if $Task.isDone %>ring-emerald-300<% else_if $Task.isSkipped %>ring-rose-300<% else_if $IsOverdue %>ring-amber-400<% end_if %>"
    x-data="{ mode: '' }">
    <div class="flex items-start gap-3 p-4">
        <div class="w-14 shrink-0 text-center">
            <div class="text-lg font-bold leading-tight <% if $IsOverdue %>text-amber-700<% end_if %>">$Task.DueTime.Format('HH:mm')</div>
            <% if $IsOverdue %><div class="text-[11px] font-semibold uppercase text-amber-700"><%t CareHomePortal.LATE "kasni" %></div><% end_if %>
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-base font-semibold">$Task.Title</span>
                <span class="ch-badge <% if $IsTherapy %>bg-violet-100 text-violet-800<% else_if $IsVitals %>bg-sky-100 text-sky-800<% else %>bg-slate-100 text-slate-700<% end_if %>">$Task.CategoryLabel</span>
            </div>
            <% if $Task.TherapyLabel %>
                <p class="mt-1 text-base font-medium text-violet-900">$Task.TherapyLabel</p>
            <% end_if %>
            <% if $Task.Instructions %>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-600">$Task.Instructions</p>
            <% end_if %>

            <% if $Task.isDone %>
                <p class="mt-2 text-sm font-semibold text-emerald-700">
                    ✓ <%t CareHomePortal.DONE_BY "Odrađeno" %> · $Task.CompletedAt.Format('HH:mm') · $Task.CompletedBy.FirstName
                </p>
                <% if $Task.VitalsSummary %><p class="text-sm text-slate-700">$Task.VitalsSummary</p><% end_if %>
                <% if $Task.ExecutionNote %><p class="whitespace-pre-line text-sm text-slate-600">$Task.ExecutionNote</p><% end_if %>
            <% else_if $Task.isSkipped %>
                <p class="mt-2 text-sm font-semibold text-rose-700">
                    ✕ <%t CareHomePortal.SKIPPED_BY "Preskočeno" %> · $Task.CompletedAt.Format('HH:mm') · $Task.CompletedBy.FirstName
                </p>
                <p class="whitespace-pre-line text-sm text-slate-700">$Task.SkipReason</p>
            <% end_if %>
        </div>
    </div>

    <% if $Task.isOpen %>
        <div class="grid grid-cols-2 gap-2 border-t border-slate-100 bg-slate-50 p-3" x-show="mode === ''">
            <% if $IsVitals %>
                <button type="button" class="ch-btn-done" @click="mode = 'done'"><%t CareHomePortal.BTN_DONE "Odrađeno" %></button>
            <% else %>
                <form method="post" action="$CompleteAction" class="contents">
                    <input type="hidden" name="$Top.SecurityTokenName" value="$Top.SecurityTokenValue">
                    <button type="submit" class="ch-btn-done w-full"><%t CareHomePortal.BTN_DONE "Odrađeno" %></button>
                </form>
            <% end_if %>
            <button type="button" class="ch-btn-skip" @click="mode = 'skip'"><%t CareHomePortal.BTN_SKIP "Preskočeno" %></button>
        </div>

        <% if $IsVitals %>
        <form method="post" action="$CompleteAction" class="space-y-3 border-t border-slate-100 bg-emerald-50 p-4" x-show="mode === 'done'" x-cloak>
            <input type="hidden" name="$Top.SecurityTokenName" value="$Top.SecurityTokenValue">
            <% loop $Definitions %>
                <div>
                    <label class="ch-label" for="v-$Up.Task.ID-$ID">$Title <% if $Unit %><span class="text-slate-500">($Unit)</span><% end_if %></label>
                    <input id="v-$Up.Task.ID-$ID" name="vitals[$ID]" class="ch-input text-lg" required
                           inputmode="<% if $ValueType == 'Integer' %>numeric<% else %>decimal<% end_if %>"
                           autocomplete="off" placeholder="$RangeLabel">
                </div>
            <% end_loop %>
            <div>
                <label class="ch-label" for="n-$Task.ID"><%t CareHomePortal.NOTE_OPTIONAL "Napomena (opcionalno)" %></label>
                <textarea id="n-$Task.ID" name="note" rows="2" class="ch-input"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="ch-btn-ghost" @click="mode = ''"><%t CareHomePortal.CANCEL "Odustani" %></button>
                <button type="submit" class="ch-btn-done"><%t CareHomePortal.SAVE "Sačuvaj" %></button>
            </div>
        </form>
        <% end_if %>

        <form method="post" action="$SkipAction" class="space-y-3 border-t border-slate-100 bg-rose-50 p-4" x-show="mode === 'skip'" x-cloak>
            <input type="hidden" name="$Top.SecurityTokenName" value="$Top.SecurityTokenValue">
            <label class="ch-label" for="r-$Task.ID"><%t CareHomePortal.SKIP_REASON "Razlog (obavezno)" %></label>
            <textarea id="r-$Task.ID" name="reason" rows="3" required minlength="3" class="ch-input"
                      placeholder="<%t CareHomePortal.SKIP_PLACEHOLDER "npr. odbio terapiju, spava, u bolnici…" %>"></textarea>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="ch-btn-ghost" @click="mode = ''"><%t CareHomePortal.CANCEL "Odustani" %></button>
                <button type="submit" class="ch-btn-skip"><%t CareHomePortal.CONFIRM_SKIP "Potvrdi" %></button>
            </div>
        </form>
    <% else_if $CanUndo %>
        <form method="post" action="$UndoAction" class="border-t border-slate-100 bg-slate-50 px-4 py-2 text-right"
              onsubmit="return confirm('<%t CareHomePortal.UNDO_CONFIRM "Poništiti ovaj unos?" %>')">
            <input type="hidden" name="$Top.SecurityTokenName" value="$Top.SecurityTokenValue">
            <button type="submit" class="text-sm font-semibold text-slate-600 underline underline-offset-4">
                <%t CareHomePortal.UNDO "Poništi (još {minutes} min)" minutes=$UndoMinutes %>
            </button>
        </form>
    <% end_if %>
</li>
