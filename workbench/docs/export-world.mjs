/**
 * Prints a storyfeed/docs checkout's world packs as JSON, for generate.php.
 *
 *   node workbench/docs/export-world.mjs <docs path>
 *
 * The world is written in TypeScript in the docs (docs/.vitepress/theme/worlds),
 * so this reads it there rather than keeping a copy here. Per pack it prints:
 *
 *   canonicalNow  the instant the rows are written against
 *   verbs         every verb's wording: headline, glyph and group headlines
 *   intents       the docs' glyph intents, by `type.verb`
 *   rows          every row, in pack order: its roles as entities, data, headline
 *   scenes        every named list of two or more rows a page draws, by its path
 *                 under `scene`, plus `everything`; read in Live, each on its own
 *   variants      the rows a page rebuilds by hand: `deletion` and `composite`
 */
import { registerHooks } from 'node:module'
import { resolve } from 'node:path'

const docs = process.argv[2] ?? (() => { throw new Error('Pass the path to a storyfeed/docs checkout.') })()
const theme = resolve(docs, 'docs/.vitepress/theme')

// The theme's modules import each other without extensions, as Vite allows.
registerHooks({
  resolve(specifier, context, next) {
    if (/^\.\.?\//.test(specifier) && !/\.\w+$/.test(specifier)) {
      for (const suffix of ['.ts', '/index.ts']) {
        try { return next(`${specifier}${suffix}`, context) } catch {}
      }
    }
    return next(specifier, context)
  },
})

const { BASE_VERBS, worldOf } = await import(`${theme}/world.ts`)
const { INTENTS } = await import(`${theme}/samples.ts`)
const { PACKS } = await import(`${theme}/worlds/index.ts`)

/** An object with keys, or null: `{}` and `[]` both mean none. */
const filled = (value) => value && !Array.isArray(value) && Object.keys(value).length ? value : null

const ROLES = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator']

/** A docs entity as the generator takes it: what its model stores. */
function entityOf(entity) {
  if (!entity) return null
  const media = Object.fromEntries(['icon', 'image', 'preview']
    .filter((slot) => entity.media?.[slot]).map((slot) => [slot, entity.media[slot]]))

  return {
    type: entity.type,
    id: String(entity.id),
    label: entity.label,
    url: entity.link?.href ?? null,
    data: filled(entity.data),
    body: entity.body ?? null,
    media: Object.keys(media).length ? media : null,
  }
}

/** Every list of two or more activities under `scene`, by its path, as live-drift.test.mjs finds them. */
function scenes(value, path = 'scene', found = {}) {
  if (Array.isArray(value)) {
    if (value.length >= 2 && value.every((node) => node?.kind === 'activity')) found[path] = value.map((node) => node.id)
    else value.forEach((item, i) => scenes(item, `${path}[${i}]`, found))
  } else if (value && typeof value === 'object' && value.kind === undefined) {
    for (const [key, item] of Object.entries(value)) scenes(item, `${path}.${key}`, found)
  }
  return found
}

const packs = {}
for (const [name, pack] of Object.entries(PACKS)) {
  const world = worldOf(pack)

  packs[name] = {
    canonicalNow: pack.canonicalNow,
    verbs: { ...BASE_VERBS, ...pack.verbs },
    intents: INTENTS,
    rows: pack.rows.map((row) => ({
      id: row.id,
      at: `${row.at.replace(' ', 'T')}:00Z`,
      verb: row.verb,
      ...Object.fromEntries(ROLES.filter((role) => row[role]).map((role) => [role, entityOf(row[role])])),
      data: filled(row.data),
      headline: row.headline ?? null,
    })),
    scenes: { ...scenes(world.scene), everything: world.everything().map((node) => node.id) },
    // Rows a page rebuilds by hand today: a deletion, and a composite of tasks.
    variants: { deletion: pack.scenes.cookbook.deletion, composite: pack.scenes.deeper.composites.tasks },
  }
}

process.stdout.write(JSON.stringify(packs))
