import { readFileSync } from 'node:fs';

// Public release metadata only; no environment or portal values are exposed.
export const version = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')).version;
