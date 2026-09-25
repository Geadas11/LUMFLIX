<?php
/**
 * [CONFIG-ARCHIVE] archive_config.php
 * Afinacoes da fila de classicos.
 *
 * Ao contrario do TMDB, o Internet Archive nao precisa de credencial: a
 * API e aberta. Este ficheiro so tem os numeros que decidem o que entra
 * na fila.
 */

/*
 * Coleccao do Archive de onde vem os filmes.
 *
 * "feature_films" sao longas metragens, cerca de 28 mil. A coleccao mais
 * larga, "moviesandfilms", tem 77 mil mas mistura tudo: lotes de videos
 * caseiros, imagens de arquivo e trailers, que nao sao filmes.
 */
const ARCHIVE_COLECCAO = 'feature_films';

/*
 * O intervalo de anos.
 *
 * NAO VALE A PENA SUBIR O LIMITE DE CIMA para apanhar filmes recentes:
 * o Internet Archive hospeda dominio publico e licencas abertas, e
 * filmes modernos nao sao nem uma coisa nem outra. De 2020 para ca o que
 * la esta e pornografia, filmes caseiros, imagens de arquivo, trailers,
 * e pirataria que acaba removida. Testado: alargar ate 2026 nao trouxe
 * um unico filme moderno, so lixo.
 *
 * Ate 2000 e o que faz sentido. O grosso e anterior a 1964, por causa
 * das regras de renovacao de registo nos EUA; depois disso ha uma mao
 * cheia de casos soltos, que este limite deixa entrar.
 *
 * Para filmes recentes o caminho e outro: trailers do TMDB, que tem
 * quase todos, ou dizer em que servico e que cada um esta.
 */
const ARCHIVE_ANO_DE = 1920;
const ARCHIVE_ANO_ATE = 2000;

// Quantos candidatos pedir ao Archive. Nem todos passam: uns nao existem
// no TMDB, outros nao tem cartaz, outros sao repetidos. De 250 costumam
// sobrar uns 84.
const ARCHIVE_CANDIDATOS = 250;

/*
 * O filtro de qualidade.
 *
 * A coleccao tem muito filme de exploitation com titulo chamativo, que e
 * precisamente o que sobe nas descargas. Em vez de manter uma lista de
 * titulos proibidos — que nunca esta completa — usa-se o TMDB como
 * crivo: um filme com cartaz e votos suficientes e um filme que alguem
 * catalogou a serio.
 */
/*
 * Cinquenta, e nao zero.
 *
 * Com zero entram 113 filmes em vez de 84, mas 56 deles tem menos de 50
 * votos — obscuridades que ninguem reconhece. Pior: a correspondencia
 * com o TMDB e feita por titulo e ano, e sem votos suficientes passam
 * enganos, como um item chamado "Shame" a apanhar o cartaz de "World
 * Without Shame". A fila mostrava um filme e tocava outro.
 */
const ARCHIVE_VOTOS_MINIMOS = 50;

// Documentario (id 99 no TMDB). A coleccao tem filmagens de guerra e de
// campos de concentracao, que nao sao para aparecer entre os filmes.
const ARCHIVE_GENERO_EXCLUIDO = 99;

// Uma semana. Os classicos nao mudam, ao contrario do que e popular hoje.
const ARCHIVE_CACHE_SEGUNDOS = 7 * 24 * 3600;

/*
 * A ficha de um item do Archive leva entre 6 e 18 segundos a chegar —
 * nao e engano, e mesmo assim. Por isso duas coisas: espera-se muito
 * mais tempo do que pelas outras APIs, e so se pede a ficha quando
 * alguem carrega em Play, nunca ao montar a fila.
 *
 * O endereco do ficheiro nunca muda, por isso guarda-se por muito tempo:
 * a espera acontece uma vez na vida de cada filme.
 */
const ARCHIVE_ESPERA_SEGUNDOS = 30;
const ARCHIVE_CACHE_VIDEO_SEGUNDOS = 90 * 24 * 3600;

/*
 * Quanto tempo um filme que nao tocou fica fora da fila.
 *
 * Um dia, e nao para sempre, porque quase todas estas falhas sao
 * passageiras: o Archive teve um mau minuto, o derivado estava a ser
 * gerado. Um bom filme nao pode desaparecer do site para sempre por
 * causa disso — passado um dia tem nova oportunidade.
 */
const ARCHIVE_ESQUECER_FALHA = 24 * 3600;

/*
 * Qual dos ficheiros de video escolher, por ordem de preferencia.
 *
 * O Archive guarda o mesmo filme em varios formatos. O "512Kb MPEG4"
 * ronda os 400 MB e comeca a tocar depressa; o "h.264" passa do meio
 * giga para o mesmo filme e so faz sentido em ligacao boa.
 */
const ARCHIVE_FORMATOS = ['512Kb MPEG4', 'MPEG4', 'h.264', 'h.264 IA'];
