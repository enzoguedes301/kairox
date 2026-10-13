# Funil de Checkout — PIX via Tronfy

Funil de páginas estáticas com um backend PHP que gera cobranças PIX pela
[Tronfy](https://api.tronfy.com.br) e confirma o pagamento por webhook.

- **Gateway:** Tronfy (`/v1/pix-charges`)
- **Rastreio:** Pixel do Meta — PageView em todas as páginas e `Purchase` na
  confirmação do pagamento, enviado tanto pelo navegador (pixel) quanto pelo
  servidor (Conversions API), para não depender do cliente manter a aba
  aberta após pagar o PIX
- **Consulta de CPF:** BlueGet / zipcardx

---

## Estrutura

```
/home/              página inicial
/2/ … /10/          etapas do funil
/pagamento/         checkout (gera o PIX)  ?offer=main|up1…up11
/upsell/1/ … /11/   upsells
/API PHP/           backend
  api.php           único endpoint público (?action=generate|status|webhook|consult_cpf|product)
  lib.php           integração Tronfy + helpers
  config.php        ofertas, preços e configuração
  settings.php      carrega os settings do painel
  secrets.php       >>> AS CHAVES. NÃO vai no git. Você cria no servidor. <<<
  data/             pedidos gravados em runtime (não vai no git)
```

---

## Requisitos do servidor

- PHP 7.4 ou superior
- Extensão **cURL** habilitada
- Apache com `.htaccess` (a HostGator usa). **Em nginx o bloqueio da pasta
  `data/` não funciona** — veja a seção de segurança.
- A pasta `API PHP/data/` precisa existir e ser **gravável** (permissão 755)

---

## Deploy na HostGator via GitHub

### 1. Suba o código

Na HostGator, pelo cPanel → **Git Version Control**, aponte para o repositório e
faça o deploy em `public_html` (ou na subpasta do domínio).

Se preferir pela linha de comando (SSH):

```bash
cd ~/public_html
git clone SEU_REPOSITORIO .
```

Para atualizar depois de um push:

```bash
cd ~/public_html && git pull
```

### 2. Crie o arquivo de chaves (passo manual, só uma vez)

O `secrets.php` **não está no repositório de propósito** — chave de pagamento
não pode ir pro GitHub. No servidor:

```bash
cd ~/public_html/"API PHP"
cp secrets.example.php secrets.php
nano secrets.php     # cole as chaves reais
```

Ou pelo cPanel → **Gerenciador de Arquivos** → entre em `API PHP` →
**+ Arquivo** → nome `secrets.php` → clique com o botão direito → **Editar** →
cole o conteúdo → Salvar.

Sem esse arquivo o PIX não é gerado, e o log de erro do PHP avisa:
`[config] FALTA A CHAVE DA TRONFY`.

### 3. Cadastre o webhook na Tronfy

Painel da Tronfy → **Integrações → Postback/Webhook** → categoria **Transações**
→ URL:

```
https://SEUDOMINIO/API PHP/api.php?action=webhook
```

Sem o webhook o pagamento ainda confirma, mas só pelo polling da página
(`?action=status`), que é mais lento e exige o cliente com a aba aberta.

### 4. Confira as permissões

```bash
chmod 755 ~/public_html/"API PHP"/data
```

### 5. Teste

Abra `https://SEUDOMINIO/home/` e vá até o checkout. Gere um PIX de verdade e
pague o upsell mais barato (R$ 16,92) para validar o fluxo completo: pedido
marcado como pago, redirect para o próximo upsell e o evento `Purchase` no
Gerenciador de Eventos do Meta.

---

## Segurança — leia antes de publicar

**Nunca comite:**

- `API PHP/secrets.php` — chaves da Tronfy e da consulta de CPF
- `API PHP/data/order_*.json` — contêm nome, CPF, e-mail e telefone de clientes
  reais. Publicar isso é vazamento de dado pessoal (LGPD).

Ambos já estão no `.gitignore`. Antes do primeiro push, confirme:

```bash
git status --ignored | grep -E "secrets|order_"
```

**Os arquivos `settings*.json` ficam no repositório de propósito** — eles guardam
os preços e os textos do funil. Mantenha `gateway.api_key` **sempre vazio**
neles; a chave mora só no `secrets.php`.

**Proteção da pasta `data/`:** existe um `.htaccess` com `Deny from all`. Isso só
funciona em **Apache**. Se um dia migrar para nginx, bloqueie manualmente:

```nginx
location ~ ^/API\ PHP/data/ { deny all; }
```

Os arquivos `config.php`, `lib.php`, `settings.php` e `secrets.php` têm um guard
(`CHECKOUT_BOOT`) e retornam **403** se acessados direto pelo navegador. O único
arquivo que responde de verdade é o `api.php`.

---

## Alterar preços e textos

Preços das ofertas: `API PHP/config.php`, bloco `offers` (valores em **centavos**).

Textos, nome do produto e valor da oferta principal: `API PHP/data/settings.json`.

Valores exibidos na página de pagamento: `PRICE_MAP` e `priceVal` em
`pagamento/index.html` — **precisam bater** com os centavos do `config.php`,
senão a página mostra um preço e a cobrança sai com outro.
