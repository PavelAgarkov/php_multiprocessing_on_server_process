<?php

namespace src;

use src\SharedMemory;
use src\WorkerProcess;
use src\DataManagerForWorkers;

/** Класс для управления параллельными php процессами взаимодействующими через разделяемую память unix.
 * Class ProcessesManager
 * @package src
 */
class ProcessesManager
{
    /**
     * @var array - записи о каналах связи.
     */
    private array $pipes = [];

    /**
     * @var array - запущенные процессы.
     */
    public array $processes = [];

    /**
     * @var array - указатели на каналы связи между процессами.
     */
    private array $processPipes = [];

    /**
     * @var array - набор воркеров
     */
    private array $poolOfWorkers;

    /**
     * @var array - массив объектов DataManager для каждого набора WorkerProcess
     */
    private array $dataManagerForWorkers;

    /**
     * @var \src\SharedMemory - объект разделяемой памяти
     */
    private SharedMemory $SharedMemory;

    /** Метод для открытия нового процесса php передающего в открытый процесс данные о номере процесса
     *  относительно родительского, а так же данные для заполнения разделяемой памяти из созданного процесса.
     * @param string $workerName - имя воркера.
     * @param int $processNumber - порядковый номер запускаемого процесса для родительского.
     * @param int $numberMemoryKey - ключ разделяемой памяти, по которому внутри воркера открывается
     *                              соединения для записи в участок разделяемой памяти.
     * @param array $descriptors - дискрипторы для настройки каналов.
     * @param int $memorySize - размер памяти для восстановления подключения к ресурсу разделяемой
     *                          памяти внутри воркера.
     */
    public function openProcess(
        string $workerName,
        int $processNumber,
        int $numberMemoryKey,
        array $descriptors,
        int $memorySize
    ): void
    {
        $unserializeFlag = 0;
        if (array_key_exists($workerName, $this->dataManagerForWorkers)) {
            $unserializeFlag = 1;
        } else $unserializeFlag = 0;

        $proc = proc_open(
            "php {$workerName}.php {$processNumber} {$numberMemoryKey} {$memorySize} {$unserializeFlag}",
            $descriptors,
            $this->processPipes);
        $this->processes[$processNumber] = $proc;
        $this->pipes[$processNumber] = $this->processPipes;
    }

    /** Метод открывает цикл процессов, который передает управление воркерам.
     *  По окончанию выполнения последнего воркера цикл возвращает управление основному процессу.
     * @return ProcessesManager
     */
    public function startProcessLoop(): ProcessesManager
    {
        foreach ($this->SharedMemory->getResourcePool() as $workerName => $configurations) {
            foreach ($configurations as $resourceKey => $value) {
                $numberMemoryKey = $value[1];

                $this->openProcess(
                    $workerName,
                    $resourceKey,
                    $numberMemoryKey,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                    ],
                    $this->poolOfWorkers[$workerName]->getMemorySize()
                );
//                printf( "<br> open {$resourceKey} <br>");
            }
        }
        // демонстрация каналов для отладки

//        while (array_filter($this->processes, function ($proc) {
//            return proc_get_status($proc)['running'];
//        })) {
//            foreach (range(0, 4) as $i) {
////        usleep(10 * 1000); // 100ms
//                // Read all available output (unread output is buffered).
//
//                $str = fread($this->pipes[$i][1], 50);
//
//                if ($str) {
//                    printf($str);
//                }
//            }
//        }
////
        return $this;
    }

    /** Метод закрывающий каналы и процессы, открытые для работы.
     * @return ProcessesManager
     */
    public function closeProcessLoop(): ProcessesManager
    {
        foreach ($this->SharedMemory->getResourcePool() as $workerName => $configurations) {
            foreach ($configurations as $resourceKey => $value) {
//                printf( "<br> close {$resourceKey} <br>");
                fclose($this->pipes[$resourceKey][1]);
                proc_close($this->processes[$resourceKey]);
            }
        }

        $this->SharedMemory->readAllDataFromResourcePool();

        return $this;
    }

    /** Метод приримает массив конфигураций, создает менеджера управления разделяемой памятью
     *  для каждого набора воркеров, создает пул ресурсов разделяемой памяти, заполняет ресурс
     *  для каждого набора воркеров разбитыми данными на воркеры.
     * @param array $workerConfigurations - массив конфигураций, включающий массивы содержащие
     *  информацию о наборе воркеров. Структура :
     * [
     * 0 - путь до файла воркера, 1 - количество воркеров,
     * 2 - память в килобайтах выделенная на один воркер,
     * 3 - массив данных необходимых для параллельной обработки
     * ]
     * если не указан 3 элемент, то в воркер не передаются данные
     * @return $this
     * @throws \Exception
     */
    public function configureProcessesLoop(array $data, string $xmlPath): ProcessesManager
    {
        $XML = new XML($xmlPath);
        $XML->addDataForJobs($data);
        $workerConfigurations = $XML->getJobs();

        $SharedMemory = new SharedMemory();
        $this->SharedMemory = $SharedMemory;

        $pool = [];
        foreach ($workerConfigurations as $key => $configuration) {
            $pool[$configuration["jobName"]] = new WorkerProcess($configuration);

            if (isset($configuration["dataPartitioning"])) {

                try {
                    if (!isset($configuration["dataPartitioning"]["flagPartitioning"]) ||
                        $configuration["dataPartitioning"]["flagPartitioning"] === null ||
                        count($configuration["dataPartitioning"]) == 1) {
                        throw new \RuntimeException('The data separator flag for workers was not specified.');
                    }
                } catch (\Exception $e) {
                    exit($e->getMessage());
                }

                $DataManager = $this->dataManagerForWorkers[$configuration["jobName"]] =
                    new DataManagerForWorkers(
                        $pool[$configuration["jobName"]],
                        $configuration["dataPartitioning"]
                    );

                if ((int)$configuration["dataPartitioning"]["flagPartitioning"] == 1) {
                    $DataManager->splitDataForWorkers();
                } else {
                    $DataManager->passCommonDataForAllWorkers();
                }
            }
        }

        $this->poolOfWorkers = &$pool;

        $this->SharedMemory->createResourcePool($this->poolOfWorkers);

        foreach ($workerConfigurations as $key => $configuration) {
            if (isset($configuration["dataPartitioning"])) {

                if ((int)$configuration["dataPartitioning"]["flagPartitioning"] == 1) {
                    $this->dataManagerForWorkers[$configuration["jobName"]]
                        ->putDataIntoWorkerSharedMemory($this->SharedMemory);
                } else {
                    $this->dataManagerForWorkers[$configuration["jobName"]]
                        ->putCommonDataIntoWorkers($this->SharedMemory);
                }

            }
        }

        return $this;
    }

    /** Метод очищает пул ресурсов от данных из воркеров
     * @return bool
     */
    public function clearResourcePool(): bool
    {
        return $this->SharedMemory->deleteAllDataFromResourcePool();
    }

    /** Метод управляет получение выходных данных из разделяемой памяти по ключу(или всех).
     * @param string|null $workerName - имя воркера
     * @return array
     */
    public function getOutputData(string $workerName = null): array
    {
        return $this->SharedMemory->getData($workerName);
    }

    /** Метод возвращает пул ресурсво из объекта разделяемой памяти
     * @return array
     */
    public function getResourceMemoryData(): array
    {
        return $this->SharedMemory->getResourcePool();
    }

    public static function runParallelJobs(array $jobs, string $xmlPath): ProcessesManager
    {
        $Processes = new ProcessesManager();
        $Processes
            ->configureProcessesLoop($jobs, $xmlPath)
            ->startProcessLoop()
            ->closeProcessLoop()
            ->clearResourcePool();
        return $Processes;
    }

}