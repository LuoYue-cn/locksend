// Browser registration of the Argon2id implementation (side-effect import).
// Order matters: argon2-pre.js must run before the Emscripten glue.
import { ready } from './argon2-pre.js';
import '../../node_modules/argon2-browser/dist/argon2.js';
import { createArgon2id } from './argon2-glue.js';
import { setArgon2id } from './kdf.js';

setArgon2id(createArgon2id(() => ready));
