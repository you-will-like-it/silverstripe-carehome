<% include YouWillLikeIT\\CareHome\\PortalHead %>
<div x-data="{ tab: 'today' }"
     x-init="tab = ({ '#notes': 'notes', '#meals': 'meals', '#history': 'history' })[window.location.hash] || 'today'">

    <%-- Header --%>
    <section class="ch-card mb-4 p-4">
        <div class="flex items-start gap-4">
            <% if $PhotoLink %>
                <img src="$PhotoLink" alt="" class="h-20 w-20 shrink-0 rounded-2xl object-cover ring-1 ring-slate-200">
            <% else %>
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-brand-100 text-2xl font-bold text-brand-800">
                    $Initials
                </div>
            <% end_if %>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold leading-tight">$Patient.FirstName $Patient.LastName</h1>
                <p class="text-slate-600">
                    <% if $Patient.Age %>$Patient.Age <%t CareHomePortal.YEARS "god." %> · <% end_if %>$BedTitle
                </p>
            </div>
        </div>

        <% if $Patient.MedicalAlerts %>
        <div class="mt-4 rounded-xl bg-rose-50 p-3 ring-1 ring-rose-200">
            <p class="text-xs font-bold uppercase tracking-wide text-rose-700"><%t CareHomePortal.ALERTS "Alergije / dijagnoze" %></p>
            <p class="whitespace-pre-line text-base font-medium text-rose-900">$Patient.MedicalAlerts</p>
        </div>
        <% end_if %>

        <% if $Patient.DietaryRequirements || $Patient.DietaryTags %>
        <div class="mt-3 rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200">
            <p class="text-xs font-bold uppercase tracking-wide text-amber-700"><%t CareHomePortal.DIET "Ishrana" %></p>
            <% if $Patient.DietaryTags %>
                <div class="my-1 flex flex-wrap gap-1">
                    <% loop $Patient.DietaryTags %><span class="ch-badge bg-amber-200 text-amber-900">$Title</span><% end_loop %>
                </div>
            <% end_if %>
            <% if $Patient.DietaryRequirements %><p class="whitespace-pre-line text-amber-900">$Patient.DietaryRequirements</p><% end_if %>
        </div>
        <% end_if %>

        <% if $Patient.EmergencyContactName || $Patient.EmergencyContactPhone %>
        <div class="mt-3 flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500"><%t CareHomePortal.EMERGENCY "Hitni kontakt" %></p>
                <p class="truncate font-medium">$Patient.EmergencyContactName <% if $Patient.EmergencyContactRelation %><span class="text-slate-500">($Patient.EmergencyContactRelation)</span><% end_if %></p>
            </div>
            <% if $Patient.EmergencyContactPhone %>
                <a href="tel:$Patient.EmergencyContactPhone" class="ch-btn-ghost shrink-0">$Patient.EmergencyContactPhone</a>
            <% end_if %>
        </div>
        <% end_if %>
    </section>

    <%-- Tabs --%>
    <nav class="sticky top-[60px] z-20 -mx-4 mb-4 bg-slate-100/95 px-4 py-2 backdrop-blur ch-no-print">
        <div class="grid grid-cols-4 gap-1 rounded-xl bg-white p-1 ring-1 ring-slate-200">
            <button type="button" class="rounded-lg px-2 py-2 text-sm font-semibold" x-bind:class="tab === 'today' ? 'bg-brand-700 text-white' : 'text-slate-600'" @click="tab = 'today'"><%t CareHomePortal.TAB_TODAY "Danas" %></button>
            <button type="button" class="rounded-lg px-2 py-2 text-sm font-semibold" x-bind:class="tab === 'history' ? 'bg-brand-700 text-white' : 'text-slate-600'" @click="tab = 'history'"><%t CareHomePortal.TAB_HISTORY "7 dana" %></button>
            <button type="button" class="rounded-lg px-2 py-2 text-sm font-semibold" x-bind:class="tab === 'notes' ? 'bg-brand-700 text-white' : 'text-slate-600'" @click="tab = 'notes'"><%t CareHomePortal.TAB_NOTES "Napomene" %></button>
            <button type="button" class="rounded-lg px-2 py-2 text-sm font-semibold" x-bind:class="tab === 'meals' ? 'bg-brand-700 text-white' : 'text-slate-600'" @click="tab = 'meals'"><%t CareHomePortal.TAB_MEALS "Obrok" %></button>
        </div>
    </nav>

    <%-- Today --%>
    <section x-show="tab === 'today'">
        <% if $OverdueTasks %>
            <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-amber-700"><%t CareHomePortal.FROM_YESTERDAY "Neodrađeno od jučer" %></h2>
            <ul class="mb-6 space-y-3">
                <% loop $OverdueTasks %><% include YouWillLikeIT\\CareHome\\TaskCard %><% end_loop %>
            </ul>
        <% end_if %>

        <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">
            <%t CareHomePortal.TODAY_LIST "Današnja lista" %> · $Today.Format('EEEE, dd.MM.')
        </h2>
        <% if $TodayTasks %>
            <ul class="space-y-3">
                <% loop $TodayTasks %><% include YouWillLikeIT\\CareHome\\TaskCard %><% end_loop %>
            </ul>
        <% else %>
            <p class="ch-card p-4 text-slate-600"><%t CareHomePortal.NO_TASKS "Nema zadataka za danas." %></p>
        <% end_if %>
    </section>

    <%-- History --%>
    <section x-show="tab === 'history'" x-cloak id="history">
        <% if $History %>
            <% loop $History %>
                <h3 class="mb-2 mt-4 text-sm font-bold uppercase tracking-wide text-slate-500 first:mt-0">$Date.Format('EEEE, dd.MM.yyyy')</h3>
                <ul class="ch-card divide-y divide-slate-100">
                    <% loop $Tasks %>
                    <li class="flex gap-3 p-3">
                        <span class="w-12 shrink-0 font-semibold text-slate-700">$DueTime.Format('HH:mm')</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">$Title <% if $TherapyLabel %><span class="font-normal text-violet-800">· $TherapyLabel</span><% end_if %></p>
                            <% if $isDone %>
                                <p class="text-sm text-emerald-700">✓ $CompletedAt.Format('HH:mm') $CompletedBy.FirstName<% if $VitalsSummary %> · <span class="text-slate-800">$VitalsSummary</span><% end_if %></p>
                            <% else_if $isSkipped %>
                                <p class="text-sm text-rose-700">✕ $SkipReason <span class="text-slate-500">($CompletedBy.FirstName)</span></p>
                            <% else %>
                                <p class="text-sm font-semibold text-amber-700"><%t CareHomePortal.NOT_RECORDED "Nije evidentirano" %></p>
                            <% end_if %>
                        </div>
                    </li>
                    <% end_loop %>
                </ul>
            <% end_loop %>
        <% else %>
            <p class="ch-card p-4 text-slate-600"><%t CareHomePortal.NO_HISTORY "Nema evidencije za prethodnih 7 dana." %></p>
        <% end_if %>
    </section>

    <%-- Notes --%>
    <section x-show="tab === 'notes'" x-cloak id="notes">
        <form method="post" action="$NoteAction" class="ch-card mb-4 space-y-3 p-4" x-data="{ severity: 'Info' }">
            <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
            <label class="ch-label" for="note-text"><%t CareHomePortal.NEW_NOTE "Nova napomena" %></label>
            <textarea id="note-text" name="text" rows="3" required class="ch-input"></textarea>
            <div class="grid grid-cols-3 gap-2" role="radiogroup">
                <% loop $SeverityOptions %>
                <label class="cursor-pointer">
                    <input type="radio" name="severity" value="$Value" class="peer sr-only" x-model="severity" <% if $Selected %>checked<% end_if %>>
                    <span class="flex min-h-12 items-center justify-center rounded-xl text-sm font-semibold ring-1 ring-slate-300 peer-checked:ring-2
                        <% if $Value == 'Critical' %>peer-checked:bg-rose-600 peer-checked:text-white peer-checked:ring-rose-600<% else_if $Value == 'Important' %>peer-checked:bg-amber-500 peer-checked:text-white peer-checked:ring-amber-500<% else %>peer-checked:bg-brand-700 peer-checked:text-white peer-checked:ring-brand-700<% end_if %>">$Label</span>
                </label>
                <% end_loop %>
            </div>
            <button type="submit" class="ch-btn-primary w-full"><%t CareHomePortal.SAVE_NOTE "Sačuvaj napomenu" %></button>
        </form>

        <% if $RecentNotes %>
        <ul class="space-y-2">
            <% loop $RecentNotes %>
            <li class="ch-card p-3 <% if $Severity == 'Critical' %>ring-2 ring-rose-400<% else_if $Severity == 'Important' %>ring-amber-300<% end_if %>">
                <div class="mb-1 flex items-center gap-2 text-xs text-slate-500">
                    <span class="ch-badge <% if $Severity == 'Critical' %>bg-rose-600 text-white<% else_if $Severity == 'Important' %>bg-amber-100 text-amber-800<% else %>bg-slate-100 text-slate-700<% end_if %>">$SeverityLabel</span>
                    <span>$Created.Format('dd.MM. HH:mm')</span>
                    <span class="ml-auto">$CreatedBy.FirstName $CreatedBy.Surname</span>
                </div>
                <p class="whitespace-pre-line">$NoteText</p>
            </li>
            <% end_loop %>
        </ul>
        <% end_if %>
    </section>

    <%-- Meals --%>
    <section x-show="tab === 'meals'" x-cloak id="meals">
        <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">
            <%t CareHomePortal.MEALS_FOR "Obroci za" %> $MealDate.Format('EEEE, dd.MM.')
        </h2>
        <% if not $MealOpen %>
            <p class="ch-card mb-3 p-4 text-amber-800"><%t CareHomePortal.MEALS_CLOSED "Izbor za sutra je zaključen u {time}." time=$MealCutoff %></p>
        <% end_if %>
        <form method="post" action="$MealAction" class="space-y-4">
            <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
            <% loop $Meals %>
            <fieldset class="ch-card p-4">
                <legend class="sr-only">$Label</legend>
                <p class="mb-2 text-lg font-semibold">$Label <% if $HasSelection %><span class="ch-badge ml-1 bg-emerald-100 text-emerald-800"><%t CareHomePortal.SELECTED "odabrano" %></span><% end_if %></p>
                <% if $Options %>
                <div class="space-y-2">
                    <% loop $Options %>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-[:checked]:bg-brand-50 has-[:checked]:ring-2 has-[:checked]:ring-brand-600">
                        <input type="radio" name="$Up.FieldName" value="$ID" class="mt-1 h-5 w-5 accent-brand-700" <% if $Selected %>checked<% end_if %> <% if not $Top.MealOpen %>disabled<% end_if %>>
                        <span>
                            <span class="block font-semibold">$OptionName</span>
                            <% if $Description %><span class="block text-sm text-slate-600">$Description</span><% end_if %>
                        </span>
                    </label>
                    <% end_loop %>
                </div>
                <% else %>
                <p class="text-slate-500"><%t CareHomePortal.NO_MENU "Jelovnik još nije unesen." %></p>
                <% end_if %>
            </fieldset>
            <% end_loop %>
            <% if $MealOpen %>
                <button type="submit" class="ch-btn-primary w-full"><%t CareHomePortal.SAVE_MEALS "Sačuvaj izbor" %></button>
            <% end_if %>
        </form>
    </section>
</div>

<% include YouWillLikeIT\\CareHome\\ScanButton %>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
