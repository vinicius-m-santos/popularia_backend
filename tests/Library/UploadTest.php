<?php

declare(strict_types=1);

namespace App\Tests\Library;

use App\Entity\LibraryDocument;
use App\Entity\LibraryFile;
use App\Library\PdfValidator;
use App\Tests\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class UploadTest extends ApiTestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        MemoryStorage::$objects = [];
        MemoryStorage::$puts = 0;
        MemoryStorage::$reads = 0;
        MemoryStorage::$fail = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function file(string $name = 'cartilha.pdf', string $mime = 'application/pdf', ?string $content = null, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'popularia-pdf-');
        \assert(is_string($path));
        $this->paths[] = $path;
        file_put_contents($path, $content ?? self::pdf());

        return new UploadedFile($path, $name, $mime, $error, true);
    }

    public static function pdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << >> /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\n\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** @return array<string, mixed> */
    private function upload(?UploadedFile $file, string $token, string $collection, ?string $clientId = null, bool $public = false, string $access = 'private'): array
    {
        $this->client->request('POST', '/api/v1/library/documents', parameters: ['metadata' => json_encode([
            'title' => 'Cartilha de trabalho', 'description' => 'Material para estudo', 'collectionId' => $collection,
            'access' => $access, 'topic' => 'trab', 'isPublic' => $public, 'clientId' => $clientId ?? Uuid::v7()->toRfc4122(),
        ], JSON_THROW_ON_ERROR)], files: null === $file ? [] : ['file' => $file], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'multipart/form-data']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{string, string} */
    private function prepare(): array
    {
        $owner = $this->createUser('camila');
        $token = $this->login($owner->getEmail())['accessToken'];
        $collection = $this->request('POST', '/library/collections', ['name' => 'Formação'], $token);
        $this->assertStatus(201);
        \assert(is_string($collection['id']));

        return [$token, $collection['id']];
    }

    public function testValidPdfDownloadAndRetryUseOneStorageObjectAndDocument(): void
    {
        [$token, $collection] = $this->prepare();
        $clientId = Uuid::v7()->toRfc4122();
        $first = $this->upload($this->file(), $token, $collection, $clientId);
        $this->assertStatus(201);
        $second = $this->upload($this->file(), $token, $collection, $clientId);
        $this->assertStatus(201);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, MemoryStorage::$puts);
        self::assertCount(1, MemoryStorage::$objects);
        self::assertSame(1, $first['pageCount']);
        self::assertSame(1, $this->em()->getRepository(LibraryDocument::class)->count([]));
        $this->client->request('GET', '/api/v1/library/documents/'.$first['id'].'/file', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->assertStatus(200);
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
    }

    public function testInvalidMimeFakeExtensionMissingAndInterruptedUploadsLeaveNoFinalEntity(): void
    {
        [$token, $collection] = $this->prepare();
        foreach ([null, $this->file('false.pdf', 'application/pdf', 'not a PDF'), $this->file('payload.exe'), $this->file(mime: 'image/png'), $this->file(error: UPLOAD_ERR_PARTIAL)] as $file) {
            $this->upload($file, $token, $collection);
            $this->assertStatus(422);
        }
        self::assertSame(0, MemoryStorage::$puts);
        self::assertSame(0, $this->em()->getRepository(LibraryDocument::class)->count([]));
    }

    public function testOversizeAndEmptyPdfAreRejectedBeforeStorage(): void
    {
        [$token, $collection] = $this->prepare();
        $file = $this->file();
        $stream = fopen($file->getPathname(), 'r+');
        \assert(is_resource($stream));
        ftruncate($stream, PdfValidator::MAX_BYTES + 1);
        fclose($stream);
        clearstatcache();
        $this->upload($file, $token, $collection);
        $this->assertStatus(422);
        $this->upload($this->file(content: ''), $token, $collection);
        $this->assertStatus(422);
        self::assertSame(0, MemoryStorage::$puts);
    }

    public function testCorruptPdfIsRejectedByParser(): void
    {
        [$token, $collection] = $this->prepare();
        $this->upload($this->file(content: "%PDF-1.4\ntruncated file\n%%EOF"), $token, $collection);
        $this->assertStatus(422);
        self::assertSame(0, MemoryStorage::$puts);
    }

    public function testStorageFailureCanRetryWithoutGhostOrDuplicateFile(): void
    {
        [$token, $collection] = $this->prepare();
        $clientId = Uuid::v7()->toRfc4122();
        MemoryStorage::$fail = true;
        $this->upload($this->file(), $token, $collection, $clientId);
        $this->assertStatus(503);
        self::assertSame(0, $this->em()->getRepository(LibraryDocument::class)->count([]));
        self::assertCount(0, MemoryStorage::$objects);
        MemoryStorage::$fail = false;
        $this->upload($this->file(), $token, $collection, $clientId);
        $this->assertStatus(201);
        self::assertSame(1, $this->em()->getRepository(LibraryFile::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(LibraryDocument::class)->count([]));
    }

    public function testMemberUploadIsRecordedAndPrivateNonmemberOrArchivedUploadIsRejected(): void
    {
        $owner = $this->createUser('camila');
        $member = $this->createUser('marina');
        $other = $this->createUser('joana');
        $comuna = $this->createComuna('privada', \App\Enum\ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $member);
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $memberToken = $this->login($member->getEmail())['accessToken'];
        $otherToken = $this->login($other->getEmail())['accessToken'];
        $collection = $this->request('POST', '/library/collections', ['name' => 'Internos', 'comunaSlug' => 'privada'], $memberToken);
        $this->assertStatus(201);
        $uploaded = $this->upload($this->file(), $memberToken, $collection['id'], access: 'members');
        $this->assertStatus(201);
        self::assertSame('Marina', $uploaded['responsible']['name']);
        self::assertSame('comuna', $uploaded['origin']['kind']);
        $this->upload($this->file(), $otherToken, $collection['id'], access: 'members');
        $this->assertStatus(403);
        $this->upload($this->file(), $memberToken, $collection['id'], public: true, access: 'members');
        $this->assertStatus(403);
        $this->request('POST', '/comunas/privada/archive', token: $ownerToken);
        $this->assertStatus(200);
        $this->upload($this->file(), $memberToken, $collection['id'], access: 'members');
        $this->assertStatus(409);
        self::assertSame(1, MemoryStorage::$puts);
    }
}
