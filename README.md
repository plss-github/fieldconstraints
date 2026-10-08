# Field Constraints (GLPI 10 e 11)

Regras, configuradas pela interface, que limitam o preenchimento de chamados, mudanças, problemas e formulários:

- **Regras de ação**: blocos por ação (solucionar, fechar, pendenciar...) com regras por item (chamado, mudança,
  problema) que impedem a ação enquanto campos escolhidos não estiverem preenchidos. Ex.: *um chamado só pode ser solucionado com tipo de solução e com "Status demanda" e "Prioridade"
  (aba "Detalhe da demanda") preenchidos.*
- **Restrições de data**: definidas junto do campo, numa aba **Restrições de data** do bloco do **Fields** ou do
  **formulário** (Formcreator no GLPI 10, formulários nativos no GLPI 11). Ex.: *"Data de término" não pode ser
  anterior a "Data de início"*, *"Data de início" não pode ser anterior a hoje*.

Substitui o plugin `limiterforchange` sem nada fixo no código: mudar uma regra não exige novo deploy (ver
*Migração do limiterforchange*).

O mesmo código roda no GLPI 10.0.x e 11.0.x (diferenças isoladas em `src/Compat.php`).

## Regras de ação

Configurar > **Regras de ação** (ou o botão de configuração do plugin em Configurar > Plugins). Requer o direito
`config` (leitura para ver, atualização para criar/editar).

As regras são organizadas em **blocos por ação**:

- **Bloco**: nome, **ação** (*Solucionar*, *Fechar*, *Pendenciar* ou outra mudança de status), entidade
  (+ subentidades) e ativo. A ação fica fixa enquanto o bloco tiver regras.
- **Regras por item** (aba do bloco): uma ou mais por item (Chamado, Mudança, Problema), cada uma com:
  - **Filtros** (aba própria da regra, ver abaixo): a quais itens a regra vale; **sem filtro = todos**.
  - **Campos exigidos**: a lista depende do item e da ação do bloco.
    - *Campos do item*: categoria, localização, origem, descrição, tempo para solução, duração total, requerente,
      grupo requerente, observador, técnico/grupo/fornecedor atribuído, item associado, ao menos uma tarefa, todas
      as tarefas concluídas, ao menos um acompanhamento, ao menos um documento, aprovação concedida.
    - *Solução* (só em Solucionar/Fechar): tipo de solução, descrição da solução, anexo da solução.
    - *Campos adicionais*: campos do plugin **Fields** ligados ao item.
  - **Mensagem personalizada**: substitui o texto padrão; a lista do que falta é sempre mostrada.

Várias regras podem valer para a mesma ação e item: todas são avaliadas e cada uma mostra sua mensagem.

### Filtros

Aba **Filtros** da regra por item (o formulário da regra só mostra o resumo, com o link para a aba). No topo, a
combinação: **todos os filtros atendidos (E)** ou **ao menos um atendido (OU)**. Abaixo, a lista de linhas
*critério - operador - valores*, ex.: *Se Categoria está sob [Rede, Impressoras] E Grupo do usuário
não é Service Desk*. Cada linha pode ser aberta para editar ou excluída na própria lista.

| Grupo | Critérios |
|---|---|
| Item | Entidade, Categoria, Tipo de chamado¹, Origem da requisição¹, Localização, Urgência, Impacto, Prioridade |
| Atores | Requerente, Grupo requerente, Técnico atribuído, Grupo atribuído |
| Usuário que executa a ação | Usuário, Grupo do usuário, Perfil do usuário (perfil ativo) |

¹ Só chamados.

| Operador | Vale quando |
|---|---|
| **é** / **não é** | o valor (um só) é / não é o do item |
| **está em** / **não está em** | algum / nenhum valor do item está na lista |
| **está sob** / **não está sob** | como *está em* / *não está em*, mas cada valor da lista cobre também tudo abaixo dele na árvore (categoria, entidade, localização, grupo): *Categoria está sob [Rede]* pega Rede, Rede > Wi-Fi, Rede > Wi-Fi > Visitantes... |

Atores e grupos do usuário podem ser vários: *Grupo requerente é X* vale se X for **um** dos grupos requerentes.
Um item sem o valor (sem categoria, sem técnico) nunca atende *é*/*está em* e sempre atende *não é*/*não está em*.
O que é avaliado é o item como vai ficar depois do salvamento (mudar a categoria e solucionar no mesmo envio usa a
categoria nova). Sem sessão (cron de fechamento automático) não há usuário: os critérios de *Usuário que executa a
ação* não são atendidos com *é*/*está em*.

A entidade do **bloco** continua sendo o primeiro recorte; o filtro de entidade serve para refinar dentro dele (ex.:
excluir uma subentidade). Um filtro com critério que o item da regra não tem (tipo de chamado numa mudança) impede a
troca do item da regra até ser removido.

### Seletor visual

Abaixo do formulário da regra por item fica sempre o formulário real do item (por padrão o último em aberto; dá
para trocar o ID). Clicar num campo o inclui na regra, clicar de novo o retira; campos de outras abas (ex.: aba do
Fields) ficam clicáveis ao abrir a aba. Trocar o item da regra recarrega o formulário mostrado. Nada é salvo no
item: envios e menus ficam bloqueados ali; falta só **Salvar** a regra. Exigências sem campo próprio (ao menos uma
tarefa, acompanhamento, aprovação...) continuam só na lista.

## Restrições de data

Ficam onde o campo é definido, e valem onde quer que ele apareça:

- **Fields**: Configurar > Campos adicionais > o bloco > aba **Restrições de data**. Vale para o campo em qualquer
  item e tela em que o bloco é exibido (aba, formulário principal, aba de outro item). Se o bloco não é exibido (ex.:
  oculto por condição do Fields), não há o que restringir.
- **Formulários**: no formulário do Formcreator (GLPI 10) ou no formulário nativo (GLPI 11, Administração >
  Formulários), aba **Restrições de data**.

Cada restrição: *campo de data* **não pode ser anterior a** / **não pode ser posterior a** **hoje**, **uma data** fixa
ou **outro campo de data** do mesmo bloco/formulário. Um campo pode ter várias (ex.: término ≥ início e
término ≤ 31/12). Cada uma pode ser desativada ou excluída na própria aba. Quem pode editar o bloco ou o formulário
pode gerenciar as restrições.

Na tela, os calendários já respeitam as restrições: o mínimo/máximo acompanha o outro campo, e mover o início
para depois do término leva o término junto (como fazia o limiter). No servidor:

| Onde a data é gravada | Comportamento |
|---|---|
| Campo de data do Fields | As datas recusadas não são gravadas; o resto do bloco é. |
| Resposta do Formcreator / formulário nativo | O envio é recusado com a mensagem. |

Uma data já gravada não é rejulgada: contra hoje ou uma data fixa, um valor mantido (ex.: um início que já passou)
continua aceito; contra outro campo, só é verificado o envio que muda um dos dois. Campo vazio não é verificado
(obrigatoriedade é configurada no próprio Fields/formulário).

## Avisos no formulário

- **Formulário de solução**: um aviso vermelho logo acima do botão lista o que falta para solucionar, e o botão fica
  desabilitado até tudo estar preenchido. Tipo, descrição e anexo da solução somem da lista conforme são
  preenchidos no próprio formulário. O que é do item (categoria, campos adicionais...) aparece com "(preencha no
  item e salve antes)", porque só conta depois de salvo.
- **Rótulos**: os campos exigidos por alguma regra ficam com o rótulo vermelho e `*`, no formulário do item, na aba
  do Fields e no formulário de solução (tipo de solução e área de arquivos). Passando o mouse aparece
  "Obrigatório para: Solucionar, Fechar...". Exigências sem campo próprio (ao menos uma tarefa, aprovação...) só
  aparecem no aviso e na mensagem de erro.

O aviso é só orientação: quem decide é sempre a verificação no servidor.

## Onde a ação é bloqueada

| Origem da mudança | Comportamento |
|---|---|
| Adicionar solução (linha do tempo, ação em massa, API) | A solução é recusada antes de ser gravada. Se a entidade fecha o chamado na hora (fechamento automático = imediato), as regras de *Fechar* também valem. |
| Acompanhamento/tarefa marcado como pendente | O acompanhamento/tarefa é recusado. |
| Aprovar a solução (acompanhamento com "aprovar") | Recusado se as regras de *Fechar* não forem atendidas. |
| Mudar o status no formulário, ação em massa, API, cron de fechamento automático | Só o status é descartado; as demais alterações do mesmo salvamento são gravadas. |

O que é avaliado é o estado **depois** do salvamento: preencher a categoria (ou um campo adicional do formulário
principal) e mudar o status no mesmo envio é aceito. Nas mudanças diretas de status para Solucionado/Fechado, os
campos de solução são conferidos na última solução não recusada.

Regras do campo adicional: lista suspensa vazia, "Sim/Não" em **Não**, data vazia e texto em branco contam como
não preenchidos. Campo apagado/desativado, ou plugin Fields desativado, não bloqueia nada.

## Limitações

- Só mudanças de status **explícitas** são interceptadas. As que o GLPI faz sozinho (ex.: Novo → Em atendimento ao
  atribuir um técnico) não passam pelo gancho.
- A criação de um item já em um status bloqueado (ex.: chamado criado como solucionado) não é verificada.
- Para as outras ações (Fechar, Pendenciar...), o formulário só marca os rótulos; o aviso detalhado aparece no
  formulário de solução e, nas demais, na mensagem de erro após enviar.

## Instalação

Copie a pasta para `plugins/` (ou `marketplace/`) como `fieldconstraints` e:

    php bin/console plugin:install fieldconstraints -u glpi
    php bin/console plugin:activate fieldconstraints

No laboratório: `make install plugin=fieldconstraints` (ou `make plugin-reset plugin=fieldconstraints` após alterar arquivos).
Depois de `make update-files`, rode `php bin/console cache:clear` no container: o GLPI 11 guarda os templates Twig
compilados (`templates/`) e não percebe a alteração sozinho. O JS não precisa disso no GLPI 11 (a URL leva a data do
arquivo); no GLPI 10, suba a versão do plugin a cada release.

Desinstalar remove as tabelas `glpi_plugin_fieldconstraints_actionblocks`, `glpi_plugin_fieldconstraints_actionrules`,
`glpi_plugin_fieldconstraints_rulefilters` e `glpi_plugin_fieldconstraints_dateconstraints`.

### Atualização da 1.0

As **categorias** e o **tipo de chamado** das regras viram filtros com o mesmo efeito (*Categoria está sob [...]* e *Tipo de chamado é ...*, combinados com E), e as colunas antigas são removidas. Basta atualizar
o plugin (Configurar > Plugins ou `php bin/console plugin:install fieldconstraints -f`).

## Migração do limiterforchange

O que o `limiterforchange` fazia no código vira regras (criadas assim no laboratório):

| limiterforchange | Field Constraints |
|---|---|
| Botão de solução escondido sem tipo de solução (chamado, mudança, problema) | Bloco *Solucionar* com 3 regras por item → *Tipo de solução* |
| Chamado: exige "Status" e "Prioridade" da aba Detalhe da demanda | Na regra por item do chamado, + os campos do Fields *Status demanda* e *Prioridade* |
| Mudança: anexo na solução (desativado no código) | *Anexo da solução*, se voltar a ser exigido |
| Datas de início/término planejadas da mudança (Fields) | No bloco do Fields: início ≥ hoje, término ≥ hoje, término ≥ início |
| Datas "início/término produção" no Formcreator | No formulário: início ≥ hoje, término ≥ início |

Diferenças:

- O botão fica **desabilitado** (com o aviso) em vez de escondido.
- Tudo é verificado também no servidor (API, ações em massa), e não só na tela.
- O limiter achava as datas de **qualquer** formulário pelo rótulo ("data", "início", "produção"); aqui cada
  formulário tem a sua regra, com as perguntas escolhidas.
- No limiter, o início não podia recuar para antes do valor atual; aqui o mínimo é hoje (ou a data atual, se já
  estiver no passado).

Desative o `limiterforchange` ao ativar estas regras, ou o formulário de solução mostra dois avisos.

## Traduções

Textos em inglês com tradução `pt_BR` em `locales/`. Depois de editar `pt_BR.po`:

    msgfmt -o locales/pt_BR.mo locales/pt_BR.po
