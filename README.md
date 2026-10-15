# PK Content Agent

WordPress-plugin voor veilige ACF Flexible Content-wijzigingen vanuit een visuele frontend-editor en chat-assistent.

## Updates

De plugin controleert publieke GitHub-releases van `Pageking/pk-content-agent` via Plugin Update Checker. Publiceer voor iedere nieuwe versie een GitHub-release met een semantische tag, bijvoorbeeld `v0.20.0`. De versie in `pk-content-agent.php` moet overeenkomen met de tag.

## Installatie

1. Activeer **PK Content Agent** in WordPress.
2. Stel onder **Instellingen → PK Content Agent** een OpenAI API-key, model en bedrijfscontext in.
3. Open als bevoegde editor een gewone frontendpagina of een post-typearchief met een ACF Flex-optionspagina.
4. Gebruik de knop **Pagina aanpassen** rechtsonder.

Voor productie kan de key buiten de database worden gezet:

```php
define( 'PK_CONTENT_AGENT_OPENAI_API_KEY', '...' );
```

## Functionaliteit

- Inventariseert alle rijen van `content_repeater → flex_content` zonder layouts hard te coderen.
- Koppelt bewerkknoppen aan de juiste ACF-rij met exacte layoutklassen; afwijkende klassennamen worden uit het layouttemplate gelezen. De filter `pkca_layout_classes` kan aanvullende klassennamen leveren.
- Toont globale blokken als gedeelde layouts, met titel, uitleg over doorwerking en een directe link die de bronlayout in WordPress uitlicht.
- Bewaart niet-verwerkte invoer per pagina in de browsertab, ook bij sluiten of herladen. **Aanpassingen annuleren** herstelt de velden naar de actuele preview.
- Toont ACF-beschrijvingen van velden, veldgroepen en verzamelvelden, inclusief uitleg over automatische terugvalwaarden. Het kopniveau staat naast de titel, zoals in wp-admin.
- Houdt tekstinvoer vast bij mediawijzigingen en voorkomt dubbele rijacties bij herhaald openen.
- Zet een batch velden als geheel klaar en blokkeert overschrijven als de broncontent ondertussen verandert.
- Bewerkiconen houden hun toegankelijke naam zonder zichtbare labeltekst naast het icoon.
- Een oogknop naast **Pagina aanpassen** toont of verbergt alle potloodjes, verwijzingen naar gedeelde layouts en beheerlinks. De keuze blijft bewaard tijdens navigeren en herladen binnen dezelfde browsertab.
- Ondersteunt ook post-typearchieven waarvan de automatische optionspagina het veld `{post_type}_content_repeater` bevat, zoals `location_content_repeater`.
- Gebruikt een vaste bedrijfsbriefing voor doelgroep, diensten, positionering, tone of voice, terminologie en controleerbare claims.
- Kan maximaal tien geselecteerde bronpagina’s als aanvullende bedrijfscontext gebruiken.
- Ondersteunt bestaande tekst-, WYSIWYG- en afbeeldingsvelden.
- ACF-galerijen kunnen zonder tussentijds sluiten worden aangevuld, opgeschoond en via slepen gesorteerd; de mediabibliotheek werkt daarbij als veilige toevoegmodus en wordt na selectie volledig opgeruimd.
- Afbeeldingen kunnen in het chatvenster worden geüpload.
- De chat blijft tijdens de bewerksessie open en bewaart de laatste 100 berichten per pagina in de browsertab.
- Meerdere opdrachten worden zonder paginaherlading direct als visuele preview gestapeld.
- Met **Aanwijzen** kan een zichtbare tekst, knop of afbeelding aan het exacte ACF-veld worden gekoppeld.
- Het venster kan via de titelbalk worden versleept en met de minknop worden ingeklapt.
- Wijzigingen zijn eerst een gebruikersgebonden preview van twee uur.
- **Wijzigingen bekijken** opent een ruim overzicht met huidige en nieuwe inhoud, afbeeldingen en galerijvolgorde. Per wijziging kun je het onderdeel op de pagina bekijken of de wijziging verwijderen.
- Opslaan en alles ongedaan maken zijn expliciete handelingen in het wijzigingenoverzicht. Fouten blijven daar zichtbaar.
- Alleen bevoegde gebruikers krijgen toegang: `edit_post` op gewone pagina’s en `edit_posts` op post-typearchieven.
- Post types kunnen via de instellingen volledig van de frontend-assistent worden uitgesloten.
- Dynamisch gekoppelde berichten en Gravity Forms-formulieren krijgen waar mogelijk een directe beheerlink; beide soorten links zijn afzonderlijk uit te schakelen via de instellingen.
- Bij overlappende sliderkaarten wordt alleen de beheerlink van de actieve, zichtbare kaart getoond.
- Breadcrumbs, andere navigatie, interne ankerlinks en links naar de huidige pagina worden niet als gekoppelde contentkaart gemarkeerd.

## Bewuste beperkingen

- De chat wijzigt één concreet veld per opdracht.
- Layouts toevoegen, verwijderen of verplaatsen wordt niet ondersteund.
- Dynamische relaties worden in de pagina-inventaris zichtbaar, maar vrije querylogica in templates wordt nog niet volledig geanalyseerd.
- Een lokale WordPress-database en API-key zijn nodig voor een end-to-end test.

## Lokale regressiecontrole

Voer vanuit de WordPress-root uit op een lokale testsite met ACF en een gevulde bronpagina:

```sh
wp eval-file wp-content/plugins/pk-content-agent-main/tests/content-regressions.php <bronpagina-id>
```

De controle maakt een tijdelijke conceptpagina, test batches, publiceren, conflicten, links en gedeelde bronverwijzingen, en verwijdert de testpagina na afloop. Op Local moet wp-cli het juiste sitesocket gebruiken.
