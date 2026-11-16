<?php

namespace App\Services;

use RuntimeException;

/** Le moteur ne connaît plus la tâche demandée (il a redémarré depuis). */
class EngineJobLost extends RuntimeException {}
