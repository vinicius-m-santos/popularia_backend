<?php

declare(strict_types=1);

namespace App\Api\Library;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use App\Library\LibraryService;
use App\Library\LibraryViews;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
final readonly class UploadController
{
    public function __construct(private LibraryService $service, private LibraryViews $views, private CurrentUser $user, private SerializerInterface $serializer, private ValidatorInterface $validator)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $this->user->get();
        $metadata = $request->request->get('metadata');
        if (!is_string($metadata) || strlen($metadata) > 16000) {
            throw new ApiProblem(422, '/errors/library-metadata', 'Confira as informações do documento e envie novamente.');
        }
        /** @var DocumentInput $input */
        $input = $this->serializer->deserialize($metadata, DocumentInput::class, 'json', ['allow_extra_attributes' => false]);
        $violations = $this->validator->validate($input, groups: ['Default', 'upload']);
        if (count($violations) > 0) {
            throw new ValidationException($violations);
        }
        $file = $request->files->get('file');
        if (null !== $file && !$file instanceof UploadedFile) {
            throw new ApiProblem(422, '/errors/pdf-missing', 'Selecione um PDF.');
        }
        $document = $this->views->one($this->service->upload($actor, $input, $file), $actor);

        return new JsonResponse($this->serializer->serialize($document, 'json'), 201, [], true);
    }
}
