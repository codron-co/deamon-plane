@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteTag> $siteTags */
    $siteTags = $siteTags ?? collect();
@endphp

{{--
    Tags and importance are Plane-side labels, not deploy operations, so they sit
    beside the selection summary instead of in the action row. Every button posts
    the surrounding bulk form: the answer re-renders the list in place and the
    selection is kept, so several tags can be applied one after another.
--}}
<div class="sites-bulk-organize" role="group" aria-label="{{ __('sites.tags.group_label') }}">
    <details class="ops-action-menu" data-ops-action-menu data-bulk-menu="tags">
        <summary class="btn btn-secondary btn-sm">{{ __('sites.tags.menu') }}</summary>
        <div class="ops-action-popover sites-tag-popover" role="menu">
            <span class="ops-menu-label">{{ __('sites.tags.apply_label') }}</span>
            @forelse ($siteTags as $siteTag)
                <div class="sites-tag-row">
                    <span class="site-tag is-{{ $siteTag->colorKey() }}">{{ $siteTag->name }}</span>
                    <button
                        type="submit"
                        class="btn btn-ghost btn-sm"
                        formaction="{{ route('ops.sites.bulk.tags') }}"
                        name="tag_op"
                        value="attach:{{ $siteTag->id }}"
                        aria-label="{{ __('sites.tags.attach_named', ['tag' => $siteTag->name]) }}"
                    >{{ __('sites.tags.attach') }}</button>
                    <button
                        type="submit"
                        class="btn btn-ghost btn-sm"
                        formaction="{{ route('ops.sites.bulk.tags') }}"
                        name="tag_op"
                        value="detach:{{ $siteTag->id }}"
                        aria-label="{{ __('sites.tags.detach_named', ['tag' => $siteTag->name]) }}"
                    >{{ __('sites.tags.detach') }}</button>
                </div>
            @empty
                <span class="ops-menu-note">{{ __('sites.tags.empty') }}</span>
            @endforelse

            <div class="ops-action-sep" role="separator"></div>
            <span class="ops-menu-label">{{ __('sites.tags.new_label') }}</span>
            <div class="sites-tag-new" data-ops-enter-scope>
                <input
                    type="text"
                    name="new_tag_name"
                    class="field-input ops-filter"
                    maxlength="{{ \App\Models\SiteTag::NAME_MAX }}"
                    autocomplete="off"
                    placeholder="{{ __('sites.tags.name_placeholder') }}"
                    aria-label="{{ __('sites.tags.name') }}"
                    data-ops-enter-submit
                >
                @include('ops.sites._tag-swatches', ['name' => 'new_tag_color', 'selected' => \App\Models\SiteTag::DEFAULT_COLOR])
                <button
                    type="submit"
                    class="btn btn-primary btn-sm"
                    formaction="{{ route('ops.sites.bulk.tags') }}"
                    name="tag_op"
                    value="create"
                    data-ops-enter-button
                >{{ __('sites.tags.create_and_attach') }}</button>
            </div>

            @if ($siteTags->isNotEmpty())
                <div class="ops-action-sep" role="separator"></div>
                <details class="sites-tag-manage">
                    <summary class="ops-menu-button">{{ __('sites.tags.manage') }}</summary>
                    @foreach ($siteTags as $siteTag)
                        <div class="sites-tag-edit" data-ops-enter-scope>
                            <input
                                type="text"
                                name="tag_names[{{ $siteTag->id }}]"
                                value="{{ $siteTag->name }}"
                                class="field-input ops-filter"
                                maxlength="{{ \App\Models\SiteTag::NAME_MAX }}"
                                autocomplete="off"
                                aria-label="{{ __('sites.tags.rename_named', ['tag' => $siteTag->name]) }}"
                                data-ops-enter-submit
                            >
                            @include('ops.sites._tag-swatches', ['name' => 'tag_colors['.$siteTag->id.']', 'selected' => $siteTag->colorKey()])
                            <div class="sites-tag-edit-actions">
                                <button
                                    type="submit"
                                    class="btn btn-secondary btn-sm"
                                    formaction="{{ route('ops.site-tags.update', $siteTag) }}"
                                    data-ops-enter-button
                                >{{ __('ops.actions.save') }}</button>
                                <button
                                    type="submit"
                                    class="btn btn-ghost btn-sm is-danger"
                                    formaction="{{ route('ops.site-tags.destroy', $siteTag) }}"
                                    data-confirm="{{ __('sites.tags.delete_confirm', ['tag' => $siteTag->name]) }}"
                                    data-confirm-title="{{ __('sites.tags.delete_title') }}"
                                    data-confirm-label="{{ __('sites.tags.delete') }}"
                                    data-confirm-danger="true"
                                >{{ __('sites.tags.delete') }}</button>
                            </div>
                        </div>
                    @endforeach
                </details>
            @endif
        </div>
    </details>

    <details class="ops-action-menu" data-ops-action-menu data-bulk-menu="importance">
        <summary class="btn btn-secondary btn-sm">{{ __('sites.importance.menu') }}</summary>
        <div class="ops-action-popover" role="menu">
            <span class="ops-menu-label">{{ __('sites.importance.set_label') }}</span>
            @foreach (array_reverse(\App\Enums\SiteImportance::cases()) as $level)
                <button
                    type="submit"
                    class="ops-menu-button"
                    role="menuitem"
                    formaction="{{ route('ops.sites.bulk.importance') }}"
                    name="importance"
                    value="{{ $level->key() }}"
                >{{ $level->label() }}</button>
            @endforeach
        </div>
    </details>
</div>
