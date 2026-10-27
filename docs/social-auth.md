# Configuração de Social Login (Google + Apple)

Guia passo a passo para configurar Google Sign-In e Sign in with Apple nos ambientes
**staging** e **production**. Desenvolvimento local não requer credenciais reais — o app
funciona em modo demonstração sem `EXPO_PUBLIC_API_URL` definida.

---

## Visão geral das variáveis

| Variável | Onde definir | Staging | Production |
|---|---|---|---|
| `GOOGLE_CLIENT_ID` | Railway (backend) | Web Client ID stg | Web Client ID prod |
| `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` | `eas.json` | Web Client ID stg | Web Client ID prod |
| `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID` | `eas.json` | iOS Client ID stg | iOS Client ID prod |
| `APPLE_BUNDLE_ID` | Railway (backend) | `com.popularia.mobile` | `com.popularia.mobile` |
| `iosUrlScheme` | `app.json` (plugin) | reversed iOS Client ID | reversed iOS Client ID |

O backend valida o `id_token` recebido do mobile conferindo o `audience` contra
`GOOGLE_CLIENT_ID`. Por isso o **Web Client ID** precisa coincidir entre backend e mobile.

---

## Google Sign-In

### 1. Google Cloud Console — criar credenciais

Acesse [console.cloud.google.com](https://console.cloud.google.com) → selecione (ou crie) o
projeto do Popularia.

> Você pode usar **um único projeto GCP** para staging e production, criando credenciais
> separadas dentro dele. Isso simplifica o billing e a gestão de quotas.

#### 1.1 Web Client ID (backend + mobile)

1. APIs & Services → Credenciais → **Criar credencial** → ID do cliente OAuth 2.0
2. Tipo de aplicativo: **Aplicativo da Web**
3. Nome: `Popularia Backend (staging)` / `Popularia Backend (production)`
4. Authorized redirect URIs: não precisa configurar (o fluxo é mobile-first; o backend só valida o token)
5. Copie o **Client ID** gerado (formato: `…apps.googleusercontent.com`)

#### 1.2 iOS Client ID

1. **Criar credencial** → ID do cliente OAuth 2.0
2. Tipo de aplicativo: **iOS**
3. Nome: `Popularia iOS (staging)` / `Popularia iOS (production)`
4. Bundle ID: `com.popularia.mobile`
5. Copie o **Client ID** gerado
6. O **reversed client ID** é o Client ID lido de trás para frente:
   `com.googleusercontent.apps.<sufixo>` → usado no `app.json`

> Para staging e production você pode usar o mesmo iOS Client ID (mesmo bundle ID),
> ou criar dois separados para rastrear uso separado no Console. Recomendado: **dois separados**.

#### 1.3 Android Client ID (um por ambiente)

O Android Client ID não vai em variável de ambiente — o SDK do Google o descobre
automaticamente pelo **package name + SHA-1 da keystore de assinatura**. Como staging e
production usam keystores diferentes no EAS, você precisa criar dois.

**Passo 1 — Gerar as keystores no EAS (ainda não existem)**

O EAS gera e custodia as keystores automaticamente no primeiro build, mas você pode
criá-las antecipadamente sem fazer build:

```bash
# Dentro do repositório popularia_mobile
cd ~/projects/popularia_mobile

# Gerar keystore de staging
eas credentials --platform android --profile staging
# Escolha: "Set up a new keystore" → "Generate new keystore"

# Gerar keystore de production
eas credentials --platform android --profile production
# Escolha: "Set up a new keystore" → "Generate new keystore"
```

> **Importante (production):** a keystore de production é vinculada ao app na Play Store.
> Se perdê-la, não consegue publicar atualizações. O EAS a armazena na nuvem por padrão —
> considere também fazer o download e guardar em local seguro:
> `eas credentials --platform android --profile production` → Download keystore.

**Passo 2 — Obter os SHA-1s**

Após gerar as keystores:

```bash
eas credentials --platform android
# Selecione o perfil → mostra o SHA-1 Certificate Fingerprint
# Repita para staging e production
```

O SHA-1 aparece na linha `SHA1 Fingerprint`, formato `AA:BB:CC:...`.

**Passo 3 — Criar os Android Client IDs no GCP**

Para cada ambiente:
1. GCP → APIs & Services → Credenciais → **Criar credencial** → ID do cliente OAuth 2.0
2. Tipo: **Android**
3. Nome: `Popularia Android (staging)` / `Popularia Android (production)`
4. Package name: `com.popularia.mobile`
5. SHA-1: cole o fingerprint obtido acima

Não é preciso guardar os Client IDs gerados em lugar nenhum — o registro no GCP é suficiente
para o SDK autenticar os builds daquela keystore.

---

### 2. Railway — variável de ambiente do backend

Para cada ambiente no Railway, defina:

```
GOOGLE_CLIENT_ID=<Web Client ID do passo 1.1>
```

- Staging: Web Client ID de staging
- Production: Web Client ID de production

---

### 3. eas.json — variáveis do mobile

O `eas.json` já tem as chaves nos perfis `staging` e `production`. Preencha os valores:

```json
"staging": {
  "env": {
    "EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID": "<Web Client ID staging>",
    "EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID": "<iOS Client ID staging>"
  }
},
"production": {
  "env": {
    "EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID": "<Web Client ID production>",
    "EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID": "<iOS Client ID production>"
  }
}
```

> `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` deve ser idêntico ao `GOOGLE_CLIENT_ID` do Railway
> para o mesmo ambiente — é ele que o backend usa para validar o `aud` do token.

---

### 4. app.json — iosUrlScheme

O plugin `@react-native-google-signin/google-signin` precisa do reversed iOS Client ID para
registrar o URL scheme e receber o callback do Google no iOS.

Em `app.json`, dentro do array `plugins`, localize a entrada do Google e substitua o placeholder:

```json
["@react-native-google-signin/google-signin", {
  "iosUrlScheme": "com.googleusercontent.apps.<sufixo-do-ios-client-id>"
}]
```

O `<sufixo-do-ios-client-id>` é a parte numérica do iOS Client ID, por exemplo:
iOS Client ID: `123456789-abc123.apps.googleusercontent.com`
→ `iosUrlScheme`: `com.googleusercontent.apps.123456789-abc123`

> Se staging e production usam iOS Client IDs diferentes, o `app.json` só suporta um valor.
> Use o de **production** para o perfil `production` e gere um build separado para staging
> atualizando o valor manualmente, ou use EAS Build hooks para substituir antes do build.
> Na prática, o mesmo iOS Client ID para ambos os ambientes é a abordagem mais simples.

---

## Sign in with Apple

### 1. Apple Developer — habilitar a capability

1. Acesse [developer.apple.com](https://developer.apple.com) → Certificates, Identifiers & Profiles
2. Identifiers → selecione `com.popularia.mobile`
3. Habilite **Sign In with Apple**
4. Salve e faça o re-provision do perfil de distribuição se necessário

Não é preciso criar nenhum Service ID para o fluxo mobile-only: o SDK do iOS usa diretamente
o bundle identifier do app.

### 2. Railway — variável de ambiente do backend

```
APPLE_BUNDLE_ID=com.popularia.mobile
```

Esse valor é o mesmo para staging e production — é o bundle identifier do app iOS, que é
o `audience` do token Apple emitido para apps nativos.

### 3. Mobile

Nenhuma variável de ambiente adicional é necessária. O `expo-apple-authentication` usa o
bundle ID nativo (`com.popularia.mobile` definido em `app.json`), que o iOS já conhece.

---

## Checklist por ambiente

### Staging

- [ ] Web Client ID criado no GCP (aplicativo da Web) para staging
- [ ] iOS Client ID criado no GCP para staging (bundle ID `com.popularia.mobile`)
- [ ] Railway staging: `GOOGLE_CLIENT_ID` = Web Client ID staging
- [ ] Railway staging: `APPLE_BUNDLE_ID` = `com.popularia.mobile`
- [ ] `eas.json` perfil `staging`: `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` e `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID` preenchidos
- [ ] Keystore Android de staging gerada no EAS (`eas credentials --platform android --profile staging`)
- [ ] Android Client ID criado no GCP com SHA-1 da keystore de staging
- [ ] `app.json`: `iosUrlScheme` atualizado com o reversed iOS Client ID
- [ ] Apple Developer: Sign In with Apple habilitado para `com.popularia.mobile`

### Production

- [ ] Web Client ID criado no GCP para production
- [ ] iOS Client ID criado no GCP para production (bundle ID `com.popularia.mobile`)
- [ ] Keystore Android de production gerada no EAS (`eas credentials --platform android --profile production`)
- [ ] Download da keystore de production feito e guardado em local seguro
- [ ] Android Client ID criado no GCP com SHA-1 da keystore de production
- [ ] Railway production: `GOOGLE_CLIENT_ID` = Web Client ID production
- [ ] Railway production: `APPLE_BUNDLE_ID` = `com.popularia.mobile`
- [ ] `eas.json` perfil `production`: `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` e `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID` preenchidos
- [ ] Build EAS production gerado após todas as variáveis preenchidas

---

## Fluxo de validação (referência)

```
Mobile                          Backend
  │                               │
  ├─ GoogleSignIn.signIn()         │
  │  ← id_token (JWT)              │
  │                               │
  ├─ POST /auth/social ───────────►│
  │   { provider: "google",        │
  │     id_token: "..." }          │
  │                               │
  │                  GoogleTokenValidator
  │                  verifica assinatura (JWKS)
  │                  verifica aud == GOOGLE_CLIENT_ID
  │                  verifica exp, iss
  │                               │
  │◄──── { access_token, ... } ───┤
```

O `id_token` enviado pelo mobile tem `aud` igual ao **Web Client ID** (não ao iOS Client ID).
Por isso o backend usa o Web Client ID para validar — e é esse valor que deve estar em
`GOOGLE_CLIENT_ID` no Railway.
