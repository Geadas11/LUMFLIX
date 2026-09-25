const express = require('express');
const WebTorrent = require('webtorrent');
const crypto = require('crypto');

const app = express();
const client = new WebTorrent();

const PORT = Number(process.env.PORT || 3000);
const STREAM_SECRET = process.env.STREAM_SECRET || 'MUDA-ESTA-CHAVE';

// Torrents atualmente carregados
const torrents = new Map();

app.use(express.json({
    limit: '1mb'
}));

/*
 * CORS.
 *
 * Se o Node estiver atrás do mesmo domínio/proxy do LUMFLIX,
 * podes depois remover isto.
 */
app.use((req, res, next) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', 'Range, Content-Type');
    res.setHeader('Access-Control-Expose-Headers',
        'Content-Length, Content-Range, Accept-Ranges, Content-Type'
    );

    if (req.method === 'OPTIONS') {
        res.sendStatus(204);
        return;
    }

    next();
});


/* -------------------------------------------------------------
 * Segurança simples
 * ------------------------------------------------------------- */

function criarToken(valor) {
    return crypto
        .createHmac('sha256', STREAM_SECRET)
        .update(valor)
        .digest('hex');
}

function tokenValido(valor, token) {
    if (!valor || !token) return false;

    const esperado = criarToken(valor);

    try {
        return crypto.timingSafeEqual(
            Buffer.from(esperado),
            Buffer.from(token)
        );
    } catch {
        return false;
    }
}


/* -------------------------------------------------------------
 * Escolher o ficheiro de vídeo
 * ------------------------------------------------------------- */

function escolherVideo(torrent) {
    const extensoes = [
        '.mp4',
        '.m4v',
        '.webm',
        '.mkv'
    ];

    const videos = torrent.files.filter(file => {
        const nome = file.name.toLowerCase();

        return extensoes.some(ext => nome.endsWith(ext));
    });

    if (!videos.length) {
        return null;
    }

    /*
     * Se houver vários vídeos, escolhe o maior.
     * Normalmente será o filme principal.
     */
    videos.sort((a, b) => b.length - a.length);

    return videos[0];
}


/* -------------------------------------------------------------
 * Adicionar torrent
 * ------------------------------------------------------------- */

async function adicionarTorrent(magnet) {
    if (!magnet) {
        throw new Error('magnet_em_falta');
    }

    /*
     * O hash serve como identificador do torrent.
     *
     * Assim, se 20 utilizadores abrirem o mesmo filme,
     * não criamos 20 torrents diferentes.
     */
    const hashMatch = magnet.match(/btih:([a-zA-Z0-9]+)/i);

    const id = hashMatch
        ? hashMatch[1].toLowerCase()
        : crypto
            .createHash('sha256')
            .update(magnet)
            .digest('hex');

    if (torrents.has(id)) {
        return torrents.get(id);
    }

    const torrent = client.add(magnet);

    const entrada = {
        id,
        torrent,
        video: null,
        pronto: false,
        criado: Date.now()
    };

    torrents.set(id, entrada);

    torrent.on('ready', () => {
        entrada.video = escolherVideo(torrent);

        if (!entrada.video) {
            console.error(
                `[TORRENT] Nenhum vídeo encontrado: ${id}`
            );
            return;
        }

        entrada.pronto = true;

        console.log(
            `[TORRENT] Pronto: ${entrada.video.name}`
        );
    });

    torrent.on('error', err => {
        console.error(
            `[TORRENT] Erro ${id}:`,
            err.message
        );
    });

    torrent.on('done', () => {
        console.log(
            `[TORRENT] Download completo: ${id}`
        );
    });

    return entrada;
}


/* -------------------------------------------------------------
 * Criar torrent
 *
 * POST /torrent
 *
 * {
 *   "magnet": "magnet:?..."
 * }
 * ------------------------------------------------------------- */

app.post('/torrent', async (req, res) => {
    try {
        const { magnet } = req.body;

        if (!magnet || !magnet.startsWith('magnet:?')) {
            return res.status(400).json({
                status: 'error',
                error: 'magnet_invalido'
            });
        }

        const entrada = await adicionarTorrent(magnet);

        res.json({
            status: 'ok',
            id: entrada.id
        });

    } catch (err) {
        console.error(err);

        res.status(500).json({
            status: 'error',
            error: err.message
        });
    }
});


/* -------------------------------------------------------------
 * Informação sobre torrent
 * ------------------------------------------------------------- */

app.get('/torrent/:id', (req, res) => {
    const entrada = torrents.get(req.params.id);

    if (!entrada) {
        return res.status(404).json({
            status: 'error',
            error: 'torrent_nao_encontrado'
        });
    }

    res.json({
        status: 'ok',
        pronto: entrada.pronto,
        ficheiro: entrada.video
            ? entrada.video.name
            : null,
        tamanho: entrada.video
            ? entrada.video.length
            : null
    });
});


/* -------------------------------------------------------------
 * STREAM
 *
 * GET /stream/:id
 *
 * Suporta:
 *
 * Range: bytes=0-
 *
 * Isto é o que permite ao <video> fazer seek.
 * ------------------------------------------------------------- */

app.get('/stream/:id', (req, res) => {
    const entrada = torrents.get(req.params.id);

    if (!entrada) {
        return res.status(404).send('Torrent não encontrado');
    }

    if (!entrada.pronto || !entrada.video) {
        return res.status(425).send(
            'O vídeo ainda não está pronto'
        );
    }

    const video = entrada.video;

    const tamanho = video.length;

    /*
     * Tipo MIME.
     */
    const nome = video.name.toLowerCase();

    let contentType = 'video/mp4';

    if (nome.endsWith('.webm')) {
        contentType = 'video/webm';
    } else if (nome.endsWith('.m4v')) {
        contentType = 'video/x-m4v';
    } else if (nome.endsWith('.mkv')) {
        contentType = 'video/x-matroska';
    }

    res.setHeader('Accept-Ranges', 'bytes');
    res.setHeader('Content-Type', contentType);

    /*
     * Sem Range:
     *
     * devolvemos o ficheiro inteiro como stream.
     */
    if (!req.headers.range) {
        res.status(200);
        res.setHeader('Content-Length', tamanho);

        const stream = video.createReadStream();

        stream.on('error', err => {
            console.error('[STREAM]', err.message);

            if (!res.headersSent) {
                res.status(500).end();
            } else {
                res.destroy();
            }
        });

        req.on('close', () => {
            stream.destroy();
        });

        stream.pipe(res);

        return;
    }


    /*
     * Range:
     *
     * Exemplo:
     *
     * bytes=1000000-
     */
    const match = req.headers.range.match(
        /bytes=(\d*)-(\d*)/
    );

    if (!match) {
        return res.status(416).end();
    }

    let inicio = match[1]
        ? Number(match[1])
        : 0;

    let fim = match[2]
        ? Number(match[2])
        : tamanho - 1;

    /*
     * Range inválido.
     */
    if (
        inicio >= tamanho ||
        fim >= tamanho ||
        inicio > fim
    ) {
        res.setHeader(
            'Content-Range',
            `bytes */${tamanho}`
        );

        return res.status(416).end();
    }

    const comprimento = fim - inicio + 1;

    res.status(206);

    res.setHeader(
        'Content-Range',
        `bytes ${inicio}-${fim}/${tamanho}`
    );

    res.setHeader(
        'Content-Length',
        comprimento
    );

    /*
     * WebTorrent vai buscar as peças necessárias
     * à medida que o browser as pede.
     */
    const stream = video.createReadStream({
        start: inicio,
        end: fim
    });

    stream.on('error', err => {
        console.error('[STREAM RANGE]', err.message);

        if (!res.headersSent) {
            res.status(500).end();
        } else {
            res.destroy();
        }
    });

    req.on('close', () => {
        stream.destroy();
    });

    stream.pipe(res);
});


/* -------------------------------------------------------------
 * Remover torrents antigos
 * ------------------------------------------------------------- */

setInterval(() => {
    const agora = Date.now();

    for (const [id, entrada] of torrents) {

        /*
         * 2 horas sem utilização.
         *
         * Mais tarde podemos substituir isto por um sistema
         * baseado no número de utilizadores ligados.
         */
        if (agora - entrada.criado > 2 * 60 * 60 * 1000) {

            console.log(
                `[TORRENT] A remover torrent antigo: ${id}`
            );

            try {
                entrada.torrent.destroy();
            } catch {}

            torrents.delete(id);
        }
    }

}, 10 * 60 * 1000);


/* ------------------------------------------------------------- */

app.listen(PORT, '0.0.0.0', () => {
    console.log('');
    console.log('======================================');
    console.log(' LUMFLIX TORRENT SERVER');
    console.log('======================================');
    console.log(` Porta: ${PORT}`);
    console.log('');
});