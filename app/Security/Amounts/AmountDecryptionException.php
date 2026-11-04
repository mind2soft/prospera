<?php

namespace App\Security\Amounts;

use Illuminate\Contracts\Encryption\DecryptException;

/**
 * An encrypted amount (or a wrapped amount key) could not be decrypted:
 * malformed payload, unknown key version, tampered ciphertext or a context
 * mismatch. Messages never contain key material or plaintext amounts.
 */
final class AmountDecryptionException extends DecryptException {}
