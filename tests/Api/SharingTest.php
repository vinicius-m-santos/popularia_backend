<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\LibraryCollection;
use App\Entity\LibraryDocument;
use App\Entity\LibraryFile;
use App\Enum\ComunaVisibility;
use App\Enum\LibraryAccess;
use App\Sharing\AssociationController;
use App\Sharing\PublicLinks;
use App\Tests\ApiTestCase;
use Symfony\Component\Uid\Uuid;

final class SharingTest extends ApiTestCase
{
    public function testPostHtmlIncludesSafeMetadataBeforeAnyJavascript(): void
    {
        $user = $this->createUser();
        $post = $this->createPost($user, null, '<b>Uma ideia</b> & uma conversa '.str_repeat('coletiva ', 40));
        $id = $post->getId()->toRfc4122();
        $this->client->request('GET', '/p/'.$id.'?access_token=never-echo-this', server: ['HTTP_HOST' => 'host-injetado.invalid']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Publicação de Camila');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost:8080/p/'.$id.'"]');
        self::assertSelectorExists('meta[property="og:url"][content="http://localhost:8080/p/'.$id.'"]');
        self::assertSelectorExists('meta[property="og:title"]');
        self::assertSelectorExists('meta[property="og:description"]');
        self::assertSelectorExists('meta[property="og:image"][content="http://localhost:8080/sharing/popularia.png"]');
        self::assertSelectorExists('meta[property="og:type"][content="article"]');
        self::assertSelectorExists('meta[name="twitter:card"]');
        self::assertSelectorExists('a[href="populariamobile://p/'.$id.'"]');
        self::assertSelectorNotExists('script');
        self::assertSelectorNotExists('article b');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('never-echo-this', $html);
        self::assertStringNotContainsString('host-injetado.invalid', $html);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, noarchive');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    public function testPrivateProfileAndItsPostsNeverLeak(): void
    {
        $user = $this->createUser('segredo');
        $user->setPrivate(true, new \DateTimeImmutable());
        $post = $this->createPost($user, null, 'TEXTO-PRIVADO');
        foreach (['/u/'.$user->getId(), '/p/'.$post->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('Segredo', $html);
            self::assertStringNotContainsString('TEXTO-PRIVADO', $html);
        }
    }

    public function testPrivateComunaAndPostsHaveGenericPreviews(): void
    {
        $user = $this->createUser();
        $comuna = $this->createComuna('reservada', ComunaVisibility::Private);
        $post = $this->createPost($user, $comuna, 'CONVERSA-RESTRITA');
        foreach (['/c/reservada', '/p/'.$post->getId()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Descrição de reservada', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('CONVERSA-RESTRITA', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testPublicProfileAndComunaHaveTheirOwnMetadata(): void
    {
        $user = $this->createUser();
        $this->createComuna('tarifa');
        $this->client->request('GET', '/u/'.$user->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[property="og:title"][content="Camila (@camila) — Popularia"]');
        $this->client->request('GET', '/c/tarifa');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[property="og:description"][content="Descrição de tarifa"]');
    }

    public function testRemovalIsReflectedOnTheNextRequestWithoutApplicationCache(): void
    {
        $post = $this->createPost($this->createUser(), null, 'ANTES-DA-REMOCAO');
        $id = $post->getId()->toRfc4122();
        $this->client->request('GET', '/p/'.$id);
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->executeStatement("UPDATE post SET status = 'removed' WHERE id = ?", [$id]);
        $this->client->request('GET', '/p/'.$id);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('ANTES-DA-REMOCAO', (string) $this->client->getResponse()->getContent());
    }

    public function testDocumentsRemainProtectedEvenWhenMarkedPublic(): void
    {
        $user = $this->createUser();
        $now = new \DateTimeImmutable();
        $collection = new LibraryCollection($user, null, $user, 'Coleção secreta', '', $now);
        $file = new LibraryFile($user, 'share-test', 'storage/SUPER-SECRET.pdf', 'secreto.pdf', str_repeat('a', 64), 100, 1, $now);
        $file->status = 'ready';
        $document = new LibraryDocument($file, $collection, $user, null, 'TITULO-RESTRITO', 'DESCRICAO-RESTRITA', LibraryAccess::Private, true, null, $now);
        foreach ([$collection, $file, $document] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->request('GET', '/d/'.$document->id);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['TITULO-RESTRITO', 'DESCRICAO-RESTRITA', 'SUPER-SECRET', 'storage/', 'signature='] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        self::assertSelectorExists('meta[property="og:title"][content="Documento no Popularia"]');
    }

    public function testInvalidAndUnknownResourcesDoNotCrash(): void
    {
        foreach (['/p/invalid', '/u/me', '/p/'.Uuid::v7(), '/c/inexistente', '/x/unknown'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testAssociationEndpointsArePublicJsonWithoutRedirects(): void
    {
        foreach (['/.well-known/apple-app-site-association', '/.well-known/assetlinks.json'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
            self::assertFalse($this->client->getResponse()->headers->has('Location'));
        }
    }

    public function testAssociationUsesOnlyConfiguredSigningIdentities(): void
    {
        $fingerprint = implode(':', array_fill(0, 32, 'AB'));
        $controller = new AssociationController('TESTTEAM01', $fingerprint.',invalid');
        $apple = json_decode((string) $controller->apple()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['TESTTEAM01.com.popularia.mobile'], $apple['applinks']['details'][0]['appIDs']);
        self::assertSame([['/' => '/p/*'], ['/' => '/u/*'], ['/' => '/c/*'], ['/' => '/d/*'], ['/' => '/e/*']], $apple['applinks']['details'][0]['components']);
        $android = json_decode((string) $controller->android()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('com.popularia.mobile', $android[0]['target']['package_name']);
        self::assertSame([$fingerprint], $android[0]['target']['sha256_cert_fingerprints']);
        self::assertSame('[]', (new AssociationController('', 'invalid'))->android()->getContent());
        self::assertSame([], json_decode((string) (new AssociationController('', ''))->apple()->getContent(), true)['applinks']['details']);
    }

    public function testAuthenticatedViewerAlsoRespectsBlocksOnPublicPages(): void
    {
        $viewer = $this->createUser();
        $author = $this->createUser('outra');
        $post = $this->createPost($author, null, 'BLOCKED-TEXT');
        $token = $this->login($viewer->getEmail())['accessToken'];
        $this->request('PUT', '/me/blocks/outra', token: $token);
        foreach (['/p/'.$post->getId(), '/u/'.$author->getId()] as $url) {
            $this->client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('BLOCKED-TEXT', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testOriginCannotContainCredentialsOrAnOpenRedirect(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PublicLinks('https://user@evil.test/path');
    }
}
