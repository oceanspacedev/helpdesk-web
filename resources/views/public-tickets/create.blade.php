@php
    use Filament\Support\Facades\FilamentView;
    use Filament\Support\Icons\Heroicon;
    use Filament\View\PanelsRenderHook;
    use Illuminate\View\ComponentAttributeBag;
    use function Filament\Support\generate_icon_html;

    if (filament()->getCurrentPanel() === null) {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->bootCurrentPanel();
    }

    static $publicHelpdeskHeadHook = false;
    if (! $publicHelpdeskHeadHook) {
        $publicHelpdeskHeadHook = true;
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_START,
            fn (): string => '<meta name="robots" content="noindex, nofollow"><meta name="description" content="Form publik untuk melaporkan kendala ke Helpdesk.">',
        );
    }
@endphp

<x-filament-panels::layout.base>
    <div class="fi-simple-layout">
        <div class="fi-simple-main-ctn">
            <main class="fi-simple-main fi-width-full">
                <div @class([
                    'relative isolate flex min-h-screen justify-center p-4',
                    'items-center' => ! $reporter,
                    'items-start py-10 sm:py-16' => $reporter,
                ])>
                    <div @class([
                        'relative w-full space-y-6',
                        'max-w-sm space-y-10' => ! $reporter,
                        'max-w-3xl' => $reporter,
                    ])>
                        <x-mekaya::brand class="mx-auto size-12" />

                        @if ($reporter)
                            @if (session('ticket_success') || session('status') || $errors->any())
                                <div class="space-y-4">
                                    @if (session('ticket_success'))
                                        @php($success = session('ticket_success'))
                                        <x-filament::callout
                                            color="success"
                                            :icon="Heroicon::CheckCircle"
                                            :heading="'Laporan '.$success['number'].' berhasil dibuat'"
                                            :description="$success['title'].' sudah diteruskan ke unit '.($success['unit'] ?: 'tujuan').'.'"
                                        />
                                    @elseif (session('status'))
                                        <x-filament::callout
                                            color="info"
                                            :icon="Heroicon::InformationCircle"
                                            :heading="session('status')"
                                        />
                                    @endif

                                    @if ($errors->any())
                                        <x-filament::callout
                                            color="danger"
                                            :icon="Heroicon::ExclamationTriangle"
                                            heading="Ada bagian yang perlu diperiksa."
                                        >
                                            <x-slot:footer>
                                                <ul class="list-disc space-y-1 ps-4 text-sm">
                                                    @foreach ($errors->all() as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            </x-slot:footer>
                                        </x-filament::callout>
                                    @endif
                                </div>
                            @endif

                            <div class="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-3 py-2.5 ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10">
                                <div class="min-w-0">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Nomor WhatsApp pelapor</div>
                                    <div class="truncate font-medium text-gray-950 dark:text-white">{{ $reporterPhone }}</div>
                                </div>
                                <form method="POST" action="{{ route('public-reporter.change') }}" data-submit-lock>
                                    @csrf
                                    <x-filament::link tag="button" type="submit" size="sm">
                                        Ganti nomor
                                    </x-filament::link>
                                </form>
                            </div>

                            <form method="POST" action="{{ route('public-tickets.store') }}" enctype="multipart/form-data" data-submit-lock class="space-y-6">
                                @csrf
                                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                                <div class="fi-sr-only" aria-hidden="true">
                                    <label for="website">Website</label>
                                    <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                                </div>

                                <x-filament::section heading="Kirim ke" :icon="Heroicon::PaperAirplane">
                                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                        <x-public-helpdesk.field id="business_entities_id" label="Entitas bisnis / cabang" :required="true" :error="$errors->first('business_entities_id')">
                                            <x-filament::input.wrapper :valid="! $errors->has('business_entities_id')" class="fi-fo-select fi-fo-select-native">
                                                <x-filament::input.select id="business_entities_id" name="business_entities_id" required>
                                                    <option value="">Pilih entitas</option>
                                                    @foreach ($options['business_entities'] as $entity)
                                                        <option value="{{ $entity['id'] }}" @selected((string) old('business_entities_id') === (string) $entity['id'])>
                                                            {{ $entity['name'] }}
                                                        </option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>

                                        <x-public-helpdesk.field id="unit_id" label="Unit tujuan" :required="true" :error="$errors->first('unit_id')">
                                            <x-filament::input.wrapper :valid="! $errors->has('unit_id')" class="fi-fo-select fi-fo-select-native">
                                                <x-filament::input.select id="unit_id" name="unit_id" required>
                                                    <option value="">Pilih unit</option>
                                                    @foreach ($options['units'] as $unit)
                                                        <option value="{{ $unit['id'] }}" @selected((string) old('unit_id') === (string) $unit['id'])>
                                                            {{ $unit['name'] }}
                                                        </option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>

                                        <x-public-helpdesk.field id="problem_category_id" label="Kategori masalah" :required="true" :error="$errors->first('problem_category_id')">
                                            <x-filament::input.wrapper :valid="! $errors->has('problem_category_id')" class="fi-fo-select fi-fo-select-native">
                                                <x-filament::input.select id="problem_category_id" name="problem_category_id" required>
                                                    <option value="">Pilih unit terlebih dahulu</option>
                                                    @foreach ($options['problem_categories'] as $category)
                                                        <option value="{{ $category['id'] }}" data-unit-id="{{ $category['unit_id'] }}"
                                                            @selected((string) old('problem_category_id') === (string) $category['id'])>
                                                            {{ $category['name'] }}
                                                        </option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>

                                        <x-public-helpdesk.field id="priority_id" label="Prioritas" :required="true" :error="$errors->first('priority_id')">
                                            <x-filament::input.wrapper :valid="! $errors->has('priority_id')" class="fi-fo-select fi-fo-select-native">
                                                <x-filament::input.select id="priority_id" name="priority_id" required>
                                                    <option value="">Pilih prioritas</option>
                                                    @foreach ($options['priorities'] as $priority)
                                                        @php($selectedPriority = old('priority_id', $defaultPriorityId))
                                                        <option value="{{ $priority['id'] }}" @selected((string) $selectedPriority === (string) $priority['id'])>
                                                            {{ $priority['name'] }}
                                                        </option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>
                                    </div>
                                </x-filament::section>

                                <x-filament::section heading="Kendala" :icon="Heroicon::ChatBubbleLeftRight">
                                    <div class="space-y-6">
                                        <x-public-helpdesk.field id="title" label="Judul" :required="true" :error="$errors->first('title')">
                                            <x-filament::input.wrapper :valid="! $errors->has('title')" class="fi-fo-text-input">
                                                <x-filament::input
                                                    id="title"
                                                    name="title"
                                                    type="text"
                                                    maxlength="255"
                                                    required
                                                    :value="old('title')"
                                                    autocomplete="off"
                                                    placeholder="Printer kasir tidak bisa mencetak"
                                                />
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>

                                        <x-public-helpdesk.field id="description" label="Uraian" :required="true" :error="$errors->first('description')">
                                            <x-filament::input.wrapper :valid="! $errors->has('description')" class="fi-fo-textarea">
                                                <textarea
                                                    id="description"
                                                    name="description"
                                                    rows="6"
                                                    maxlength="60000"
                                                    required
                                                    placeholder="Apa yang terjadi, sejak kapan, dampaknya, dan yang sudah dicoba."
                                                    class="block w-full resize-y border-none bg-transparent px-3 py-1.5 text-base text-gray-950 outline-none placeholder:text-gray-400 focus:ring-0 sm:text-sm dark:text-white"
                                                >{{ old('description') }}</textarea>
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>
                                    </div>
                                </x-filament::section>

                                <x-filament::section heading="Lampiran" :icon="Heroicon::PaperClip">
                                    <label class="fi-sr-only" for="supporting_attachments">Lampiran</label>
                                    <input
                                        class="fi-sr-only"
                                        id="supporting_attachments"
                                        name="supporting_attachments[]"
                                        type="file"
                                        multiple
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.jpg,.jpeg,.png"
                                    >
                                    <div class="flex flex-wrap items-center gap-3">
                                        <x-filament::button type="button" color="gray" outlined id="pick-files">
                                            Pilih file
                                        </x-filament::button>
                                        <span id="attachment-summary" class="text-sm text-gray-500 dark:text-gray-400">Opsional · maks. 5 file, 10 MB</span>
                                    </div>
                                    <ul class="mt-2 divide-y divide-gray-200 text-sm text-gray-600 dark:divide-white/10 dark:text-gray-300" id="attachment-list" hidden></ul>
                                    @error('supporting_attachments')
                                        <p data-validation-error class="fi-fo-field-wrp-error-message">{{ $message }}</p>
                                    @enderror
                                    @error('supporting_attachments.*')
                                        <p data-validation-error class="fi-fo-field-wrp-error-message">{{ $message }}</p>
                                    @enderror
                                </x-filament::section>

                                <x-filament::button type="submit" class="w-full">
                                    Kirim laporan
                                </x-filament::button>
                            </form>
                        @else
                            <x-mekaya::card class="w-full max-w-sm p-1.5 [&>div:first-of-type]:shadow-[0_1px_16px_-2px_rgba(63,63,71,0.2)]">
                                <header class="flex flex-col items-center justify-center py-3">
                                    <div class="flex items-center justify-center rounded-lg bg-white p-2 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700/80">
                                        {{
                                            generate_icon_html(
                                                Heroicon::DevicePhoneMobile,
                                                attributes: (new ComponentAttributeBag)->class(['size-5']),
                                            )
                                        }}
                                    </div>
                                    <h1 class="mt-4 font-heading text-lg font-medium text-gray-950 dark:text-white">
                                        Buat laporan
                                    </h1>
                                </header>

                                <div class="mt-8 space-y-6">
                                    @if (session('ticket_success'))
                                        @php($success = session('ticket_success'))
                                        <x-filament::callout
                                            color="success"
                                            :icon="Heroicon::CheckCircle"
                                            :heading="'Laporan '.$success['number'].' berhasil dibuat'"
                                            :description="$success['title'].' sudah diteruskan ke unit '.($success['unit'] ?: 'tujuan').'.'"
                                        />
                                    @elseif (session('status'))
                                        <x-filament::callout
                                            color="info"
                                            :icon="Heroicon::InformationCircle"
                                            :heading="session('status')"
                                        />
                                    @endif

                                    @if ($errors->any())
                                        <x-filament::callout
                                            color="danger"
                                            :icon="Heroicon::ExclamationTriangle"
                                            heading="Ada bagian yang perlu diperiksa."
                                        >
                                            <x-slot:footer>
                                                <ul class="list-disc space-y-1 ps-4 text-sm">
                                                    @foreach ($errors->all() as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            </x-slot:footer>
                                        </x-filament::callout>
                                    @endif

                                    <form method="POST" action="{{ route('public-reporter.identify') }}" data-submit-lock class="space-y-6">
                                        @csrf
                                        <div class="fi-sr-only" aria-hidden="true">
                                            <label for="identity_website">Website</label>
                                            <input id="identity_website" name="website" type="text" tabindex="-1" autocomplete="off">
                                        </div>

                                        <x-public-helpdesk.field
                                            id="phone"
                                            label="Nomor WhatsApp"
                                            :required="true"
                                            :error="$errors->first('phone')"
                                            hint="Gunakan format 08… atau 62…"
                                        >
                                            <x-filament::input.wrapper :valid="! $errors->has('phone')" class="fi-fo-text-input">
                                                <x-filament::input
                                                    id="phone"
                                                    name="phone"
                                                    type="tel"
                                                    inputmode="tel"
                                                    maxlength="30"
                                                    required
                                                    :value="old('phone')"
                                                    autocomplete="tel"
                                                    autofocus
                                                    placeholder="0812 3456 7890"
                                                />
                                            </x-filament::input.wrapper>
                                        </x-public-helpdesk.field>

                                        <x-filament::button type="submit" class="w-full">
                                            Lanjut isi laporan
                                        </x-filament::button>
                                    </form>
                                </div>
                            </x-mekaya::card>
                        @endif

                        <div class="text-center">
                            <x-filament::link :href="route('filament.admin.auth.login')" color="gray" size="sm">
                                Masuk/Login
                            </x-filament::link>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    @push('scripts')
        <script>
            const unitSelect = document.getElementById('unit_id');
            const categorySelect = document.getElementById('problem_category_id');

            function syncCategories(clearInvalid = false) {
                if (!unitSelect || !categorySelect) return;

                const unitId = unitSelect.value;
                const selected = categorySelect.options[categorySelect.selectedIndex];

                Array.from(categorySelect.options).forEach((option, index) => {
                    if (index === 0) return;
                    const visible = unitId !== '' && option.dataset.unitId === unitId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                });

                if (clearInvalid && selected && selected.value && selected.dataset.unitId !== unitId) {
                    categorySelect.value = '';
                }
                categorySelect.options[0].textContent = unitId ? 'Pilih kategori' : 'Pilih unit terlebih dahulu';
            }

            if (unitSelect && categorySelect) {
                syncCategories(false);
                unitSelect.addEventListener('change', () => syncCategories(true));
            }

            const attachmentInput = document.getElementById('supporting_attachments');
            const attachmentList = document.getElementById('attachment-list');
            const attachmentSummary = document.getElementById('attachment-summary');
            const maxFiles = 5;
            const maxBytes = 10 * 1024 * 1024;

            document.getElementById('pick-files')?.addEventListener('click', () => {
                attachmentInput?.click();
            });

            function formatSize(bytes) {
                return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
            }

            attachmentInput?.addEventListener('change', () => {
                const files = Array.from(attachmentInput.files ?? []);
                if (!attachmentList) return;

                if (!files.length) {
                    attachmentList.hidden = true;
                    attachmentList.replaceChildren();
                    if (attachmentSummary) attachmentSummary.textContent = 'Opsional · maks. 5 file, 10 MB';
                    return;
                }

                const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
                const overLimit = files.length > maxFiles || totalBytes > maxBytes;
                if (attachmentSummary) {
                    attachmentSummary.textContent = overLimit
                        ? `${files.length} file · ${formatSize(totalBytes)} — melebihi batas`
                        : `${files.length} file · ${formatSize(totalBytes)}`;
                }
                attachmentList.hidden = false;
                attachmentList.replaceChildren(...files.map((file) => {
                    const item = document.createElement('li');
                    item.className = 'flex justify-between gap-3 py-1.5';
                    if (overLimit) item.classList.add('fi-fo-field-wrp-error-message');
                    const name = document.createElement('span');
                    name.className = 'truncate';
                    name.textContent = file.name;
                    const size = document.createElement('span');
                    size.textContent = formatSize(file.size);
                    item.append(name, size);
                    return item;
                }));
            });

            document.querySelectorAll('form[data-submit-lock]').forEach((form) => {
                form.addEventListener('submit', () => {
                    form.querySelectorAll('button[type="submit"]').forEach((button) => {
                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');
                        button.classList.add('fi-disabled');
                    });
                });
            });
        </script>
    @endpush
</x-filament-panels::layout.base>
