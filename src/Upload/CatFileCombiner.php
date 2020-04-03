<?php

/**
 * Created by PhpStorm.
 * User: leodanielstuder
 * Date: 04.06.19
 * Time: 16:56
 */

namespace le0daniel\Laravel\ResumableJs\Upload;

use le0daniel\Laravel\ResumableJs\Contracts\FileCombiner;
use Symfony\Component\Process\Process;

class CatFileCombiner implements FileCombiner
{

    /**
     * Combine the files together
     *
     * @param array $filesToCombine
     * @param string $absoluteOutputPath
     * @return bool
     */
    public function combineFiles(array $filesToCombine, string $absoluteOutputPath): bool
    {
        // Merge All files together
        $command = sprintf(
            'cat %s > %s',
            implode(' ', array_map('escapeshellarg', $filesToCombine)),
            escapeshellarg($absoluteOutputPath)
        );

        // Set the timeout depending on the mode the app is running
        // Larger files are always processed in the background
        $timeout = 60;
        if (app()->runningInConsole()) {
            $timeout = 240;
        }

        // Run the command
        (Process::fromShellCommandline($command))->setTimeout($timeout)->mustRun();

        return file_exists($absoluteOutputPath);
    }
}