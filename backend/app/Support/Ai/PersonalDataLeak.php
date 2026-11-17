<?php

namespace App\Support\Ai;

use RuntimeException;

/** Dilempar bila data yang akan dikirim ke model AI masih memuat data pribadi. Panggilan dibatalkan. */
class PersonalDataLeak extends RuntimeException {}
