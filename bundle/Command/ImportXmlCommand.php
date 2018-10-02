<?php
/**
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace EzSystems\EzPlatformXmlTextFieldTypeBundle\Command;

use DOMDocument;
use PDO;
use Psr\Log\LogLevel;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use eZ\Publish\Core\FieldType\XmlText\Converter\RichText as RichTextConverter;

class ImportXmlCommand extends ContainerAwareCommand
{
    /**
     * @var RichTextConverter
     */
    private $converter;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $dbal;

    /**
     * @var string
     */
    private $exportDir;

    /**
     * @var array.
     */
    protected $imageContentTypeIdentifiers;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct(Connection $dbal, RichTextConverter $converter)
    {
        parent::__construct();
        $this->converter = $converter;
        $this->dbal = $dbal;
        $this->exportDir = null;
    }

    protected function configure()
    {
        $this
            ->setName('ezxmltext:import-xml')
            ->setDescription(<<< EOT
Imports dumps made by ezxmltext:convert-to-richtext and which has been manually corrected by user

== WARNING ==

ALWAYS make sure you have a restorable backup of your database before using this!
EOT
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run the converter without writing anything to the database'
            )
            ->addOption(
                'export-dir',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to store ezxmltext which the conversion tool is not able to convert. You may use the ezxmltext:import-xml tool to fix such problems'
            )
            ->addOption(
                'image-content-types',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of content type identifiers which are considered as images when converting embedded tags. Default value is image'
            )
            ->addOption(
                'content-object',
                null,
                InputOption::VALUE_OPTIONAL,
                'Only import dump files for the content object with the given id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = false;
        if ($input->getOption('dry-run')) {
            $output->writeln("Running in dry-run mode. No changes will actually be written to database\n");
            $dryRun = true;
        }

        $this->output = $output;

        $contentObjectId = $input->getOption('content-object');

        if ($input->getOption('export-dir')) {
            $this->exportDir = $input->getOption('export-dir');
            if (!is_dir($this->exportDir) && is_readable($this->exportDir)) {
                new RuntimeException("$this->exportDir is not readable");
            }
        }

        if ($input->getOption('image-content-types')) {
            $this->imageContentTypeIdentifiers = explode(',', $input->getOption('image-content-types'));
        } else {
            $this->imageContentTypeIdentifiers = ['image'];
        }
        $imageContentTypeIds = $this->getContentTypeIds($this->imageContentTypeIdentifiers);
        if (count($imageContentTypeIds) !== count($this->imageContentTypeIdentifiers)) {
            throw new RuntimeException('Unable to lookup all content type identifiers, not found : ' . implode(',', array_diff($this->imageContentTypeIdentifiers, array_keys($imageContentTypeIds))));
        }
        $this->converter->setImageContentTypes($imageContentTypeIds);

        $this->importDumps($dryRun, $contentObjectId);
    }

    protected function getContentTypeIds($contentTypeIdentifiers)
    {
        $query = $this->dbal->createQueryBuilder();

        $query->select('c.identifier, c.id')
            ->from('ezcontentclass', 'c')
            ->where(
                $query->expr()->in(
                    'c.identifier',
                    ':contentTypeIdentifiers'
                )
            )
            ->setParameter(':contentTypeIdentifiers', $contentTypeIdentifiers, Connection::PARAM_STR_ARRAY);

        $statement = $query->execute();

        $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($columns as $column) {
            $result[$column['identifier']] = $column['id'];
        }

        return $result;
    }

    protected function importDumps($dryRun, $contentObjectId = null)
    {
        foreach (new \DirectoryIterator($this->exportDir) as $dirItem) {
            if ($dirItem->isFile() && $dirItem->getExtension() === 'xml') {
                $fileNameArray = explode('_', $dirItem->getBasename('.xml'));
                if (
                    count($fileNameArray) !== 5 ||
                    $fileNameArray[0] !== 'ezxmltext' ||
                    !is_numeric($fileNameArray[1]) ||
                    !is_numeric($fileNameArray[2]) ||
                    !is_numeric($fileNameArray[3]) ||
                    $fileNameArray[4] == ''
                ) {
                    $this->output->writeln('Filename pattern not recognized : ' . $dirItem->getFilename());
                    continue;
                }
                $objectId = $fileNameArray[1];
                $attributeId = $fileNameArray[2];
                $version = $fileNameArray[3];
                $language = $fileNameArray[4];
                $filename = $this->exportDir . DIRECTORY_SEPARATOR . $dirItem->getFilename();

                if ($contentObjectId !== null && $contentObjectId !== $objectId) {
                    continue;
                }

                $this->output->writeln('Importing : ' . $dirItem->getFilename());
                $this->importXml($dryRun, $filename, $objectId, $attributeId, $version, $language);
            }
        }
    }

    protected function validateConversion(DOMDocument $xmlDoc, $filename, $attributeId)
    {
        $this->converter->convert($xmlDoc, true, true, $attributeId);
        // Looks like XSLT processor is setting formatOutput to true
        $xmlDoc->formatOutput = false;
        $errors = $this->converter->getErrors();
        $result = false;

        if (!empty($errors)) {
            if (array_key_exists(LogLevel::ERROR, $errors)) {
                $this->output->writeln("Error: Validation errors when trying to convert ezxmltext in file $filename to richtext, skipping :");
            } else {
                $this->output->writeln("Warning: Issues found when trying to convert ezxmltext in file $filename to richtext:");
                $result = true;
            }
            foreach ($errors as $logLevel => $logErrors) {
                foreach ($logErrors as $logError) {
                    $this->output->writeln("- $logLevel: " . $logError['message']);
                    if (array_key_exists('errors', $logError['context'])) {
                        foreach ($logError['context']['errors'] as $contextError) {
                            $this->output->writeln('  - context: ' . $contextError);
                        }
                    }
                }
            }
        } else {
            $result = true;
        }

        return $result;
    }

    protected function contentObjectAttributeExists($objectId, $attributeId, $version, $language)
    {
        $query = $this->dbal->createQueryBuilder();
        $query->select('count(a.id)')
            ->from('ezcontentobject_attribute', 'a')
            ->where(
                $query->expr()->eq(
                    'a.data_type_string',
                    ':datatypestring'
                )
            )
            ->andWhere(
                $query->expr()->eq(
                    'a.contentobject_id',
                    ':objectid'
                )
            )
            ->andWhere(
                $query->expr()->eq(
                    'a.id',
                    ':attributeid'
                )
            )
            ->andWhere(
                $query->expr()->eq(
                    'a.version',
                    ':version'
                )
            )
            ->andWhere(
                $query->expr()->eq(
                    'a.language_code',
                    ':language'
                )
            )
            ->setParameter(':datatypestring', 'ezxmltext')
            ->setParameter(':objectid', $objectId)
            ->setParameter(':attributeid', $attributeId)
            ->setParameter(':version', $version)
            ->setParameter(':language', $language);

        $statement = $query->execute();
        $count = (int)$statement->fetchColumn();

        return $count === 1;
    }

    protected function updateContentObjectAttribute($xml, $objectId, $attributeId, $version, $language)
    {
        $updateQuery = $this->dbal->createQueryBuilder();
        $updateQuery->update('ezcontentobject_attribute')
            ->set('data_text', ':newxml')
            ->where(
                $updateQuery->expr()->eq(
                    'data_type_string',
                    ':datatypestring'
                )
            )
            ->andWhere(
                $updateQuery->expr()->eq(
                    'contentobject_id',
                    ':objectid'
                )
            )
            ->andWhere(
                $updateQuery->expr()->eq(
                    'id',
                    ':attributeid'
                )
            )
            ->andWhere(
                $updateQuery->expr()->eq(
                    'a.version',
                    ':version'
                )
            )
            ->andWhere(
                $updateQuery->expr()->eq(
                    'a.language_code',
                    ':language'
                )
            )
            ->setParameter(':newxml', $xml)
            ->setParameter(':datatypestring', 'ezxmltext')
            ->setParameter(':objectid', $objectId)
            ->setParameter(':attributeid', $attributeId)
            ->setParameter(':version', $version)
            ->setParameter(':language', $language);
        $updateQuery->execute();
    }

    protected function createDocument($xmlString)
    {
        $document = new DOMDocument();

        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        // In dev mode, symfony may throw Symfony\Component\Debug\Exception\ContextErrorException
        try {
            $result = $document->loadXml($xmlString);
            if ($result === false) {
                throw new RuntimeException('Unable to parse ezxmltext. Invalid XML format');
            }
        } catch (ContextErrorException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode());
        }

        return $document;
    }

    protected function importXml($dryRun, $filename, $objectId, $attributeId, $version, $language)
    {
        $xml = file_get_contents($filename);
        if ($xml === false) {
            new RuntimeException("Unable to read file: $filename");
        }

        $xmlDoc = $this->createDocument($xml);
        if (!$this->validateConversion($xmlDoc, $filename, $attributeId)) {
            return;
        }

        if ($this->contentObjectAttributeExists($objectId, $attributeId, $version, $language)) {
            if (!$dryRun) {
                $this->updateContentObjectAttribute($xmlDoc->saveXML(), $objectId, $attributeId, $version, $language);
            }
        } else {
            $this->output->writeln("Warning: The file $filename doesn't match any contentobject attribute stored in the database, skipping");

            return;
        }
    }
}
