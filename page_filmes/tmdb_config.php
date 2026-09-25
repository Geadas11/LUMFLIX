<?php
/**
 * [CONFIG-TMDB] tmdb_config.php
 * Credencial da API da TMDB.
 *
 * Fica aqui, no servidor, e so o tmdb.php a usa. Nunca vai para o
 * JavaScript: se fosse para a pagina, qualquer pessoa que abrisse o
 * codigo-fonte a via e podia gasta-la em nome desta conta.
 *
 * Usa-se o "API Read Access Token" (v4), e nao a "API Key" (v3), porque
 * o token viaja num cabecalho Authorization em vez de ir no endereco —
 * assim nao fica escrito nos registos de acesso do servidor.
 *
 * themoviedb.org -> Settings -> API -> API Read Access Token
 */

const TMDB_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiI3ZjQ1ZmY2YzE1NmIwM2VmNmQ0NGQ1YjAyMGI1OWYwNyIsIm5iZiI6MTc5MDI1OTgxMi45MjksInN1YiI6IjZhYjUzMjY0M2FhN2Q3YTNlMzYxNjg3NSIsInNjb3BlcyI6WyJhcGlfcmVhZCJdLCJ2ZXJzaW9uIjoxfQ.AiCoUC5aXF69W4ITaWsH7rMoqTNRjHKPMjwAm0khlAE';

// Quanto tempo guardar a resposta da TMDB antes de voltar a pedir. Sem
// isto, cada visita a pagina disparava pedidos a mais.
const TMDB_CACHE_SEGUNDOS = 6 * 3600;

/*
 * Quanto do TMDB e que o catalogo abrange. Cada pagina sao 20 titulos.
 *
 * Sao tres fontes diferentes de proposito: os populares dao o que esta a
 * dar agora, os melhor pontuados trazem os classicos que a lista do que e
 * popular hoje nunca traz, e depois vai-se genero a genero para nenhuma
 * fila ficar curta.
 *
 * Subir estes numeros aumenta o catalogo. Custa mais um pedido ao TMDB por
 * pagina, mas so na primeira visita depois de a cache expirar.
 */
const TMDB_PAGINAS_POPULARES = 3;
const TMDB_PAGINAS_TOPO = 3;
const TMDB_PAGINAS_POR_GENERO = 3;

// Quantos pedidos ao TMDB de cada vez. Em fila indiana o catalogo levava
// mais de meio minuto a montar; em paralelo leva poucos segundos.
const TMDB_EM_PARALELO = 12;

// Um titulo sem votos suficientes nao tem nota nem sinopse que se
// aproveite, e as filas enchiam-se de coisas que ninguem reconhece.
const TMDB_VOTOS_MINIMOS = 80;

// Onde vivem as imagens. w500 chega para o tamanho a que as mostramos.
const TMDB_IMAGENS = 'https://image.tmdb.org/t/p/w500';

// Fundo do destaque: ocupa a largura do ecra, por isso pede-se maior.
const TMDB_FUNDOS = 'https://image.tmdb.org/t/p/w1280';
