/**
 * Deterministic Fisher–Yates shuffle for attempt-stable bank/question order.
 *
 * Algorithm (also implemented in app/Support/SeededShuffle.php — keep the two
 * in lockstep; the shared fixture test proves they agree):
 *
 * 1. Derive a 32-bit seed: the low 32 bits of an FNV-1a hash of
 *    `shuffle_seed + "\0" + block_id` (UTF-8 bytes).
 * 2. Advance a Mulberry32 PRNG for each Fisher–Yates swap.
 * 3. Shuffle a copy; never mutate the caller's array.
 */

export function seededShuffle(items, shuffleSeed, blockId) {
  const list = [...items];
  const count = list.length;

  if (count < 2) {
    return list;
  }

  let state = hashSeed(String(shuffleSeed ?? ''), String(blockId ?? ''));

  for (let index = count - 1; index > 0; index -= 1) {
    state = mulberry32(state);
    const swap = randomIndex(state, index + 1);

    [list[index], list[swap]] = [list[swap], list[index]];
  }

  return list;
}

export function hashSeed(shuffleSeed, blockId) {
  const bytes = new TextEncoder().encode(`${shuffleSeed}\0${blockId}`);
  let hash = 2166136261;

  for (let i = 0; i < bytes.length; i += 1) {
    hash ^= bytes[i];
    hash = Math.imul(hash, 16777619) >>> 0;
  }

  return hash >>> 0;
}

/** Mulberry32 step: returns the next state word. */
export function mulberry32(state) {
  let t = (state + 0x6d2b79f5) >>> 0;
  t = Math.imul(t ^ (t >>> 15), t | 1) >>> 0;
  t = (t ^ (t + Math.imul(t ^ (t >>> 7), t | 61))) >>> 0;

  return (t ^ (t >>> 14)) >>> 0;
}

export function randomIndex(state, bound) {
  return Math.floor((state / 4294967296) * bound);
}
