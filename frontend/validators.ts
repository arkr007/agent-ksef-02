export function hasValue(value: string): boolean {
  return value.trim().length > 0;
}

export function isIsoDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value);
}
