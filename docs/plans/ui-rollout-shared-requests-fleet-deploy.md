# Shared CSS/JS requests — Fleet + Deployments

From the Fleet / Deployment rollout worktree (`ui/rollout-fleet-deploy`). Do not implement these here; layout CSS/JS are owned by the foundation pass.

## Rename Site primitives to ops-*

These classes are already the product language and are reused on Fleet and Deployment show:

- `site-hint` → `ops-hint` (heading and table-header `i` tooltips)
- `site-section-heading`, `site-section-kicker` → `ops-section-heading`, `ops-section-kicker`
- `site-metric-grid`, `site-metric`, `site-metric-icon` → `ops-metric-*`
- `site-overview-grid`, `site-card`, `site-card-head`, `site-next-action` → `ops-overview-grid`, `ops-card`, `ops-next-action`
- `site-hero` / `site-hero-main` / `site-title-row` for non-site detail headers

Keep `site-*` only where the composition is Site-specific (identity mark, favicon host).

## Fleet attention layout

`.fleet-body` is a single column. On 1440+ it should become a 2–3 column grid so Unhealthy / Failed / Dockerfile sit side by side instead of a tall stack. Unhealthy and failed panels need a danger-toned surface; Dockerfile can stay warning. Today every `.fleet-attention` block shares the warning tokens.

## Next-action row

`.site-next-action` stacks copy above actions. Deployment show fakes “copy left / button right” with `.ops-copy-row`. A shared next-action primitive should be `display: flex; justify-content: space-between; align-items: center` (column wrap under 768), matching the prototype `.next` card.

## KPI tone

`.kpi-card` has no success/warning/danger emphasis. Fleet mutes zero values with `.muted` only. A shared `is-alert` / `is-quiet` tone on the card (border + value color, not a new decorative widget) would make non-zero unhealthy/failed counts scan faster.

## Compact nested table

`.deployments-panel` still carries a large top margin/border for the old Site-edit embed. Site detail already zeros that via `.site-section-surface > section`. A density modifier (`.ops-table.is-compact` or `.deployments-panel` when nested) would keep the include API and tighten row padding.

## Attention rows as clickable rows

Fleet attention is a list of links, not `tr[data-href]`. A shared non-table row primitive (keyboard Enter, hover/focus, nested controls ignored) would match the table contract without turning attention into a table.

## Controller payload (out of Blade scope)

`FleetController` does not pass pending/provisioning or `needs_secret` site lists. Blade can only reorder Unhealthy, Failed deploys, and Dockerfile pack sites. A `pendingSites` / `needsSecretSites` collection on the existing KPI service is required before those queues can appear as attention lists.
