/**
 * Shared length rule for short_response and CER fields.
 *
 * When minLength is null/undefined, any non-empty trimmed value satisfies.
 * Otherwise the trimmed length must meet the minimum. A response block
 * always asks for something — empty never counts.
 */
export function meetsLengthRequirement(value, minLength) {
  const trimmed = String(value ?? '').trim();

  if (minLength === null || minLength === undefined) {
    return trimmed.length > 0;
  }

  const minimum = Number(minLength);

  if (! Number.isFinite(minimum) || minimum < 0) {
    return trimmed.length > 0;
  }

  return trimmed.length >= minimum;
}

export function remainingCharacters(value, minLength) {
  if (minLength === null || minLength === undefined) {
    return null;
  }

  const minimum = Number(minLength);

  if (! Number.isFinite(minimum)) {
    return null;
  }

  return minimum - String(value ?? '').trim().length;
}
