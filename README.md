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

- Inventariseert `content_repeater → flex_content` zonder layouts hard te coderen.
- Ondersteunt ook post-typearchieven waarvan de automatische optionspagina het veld `{post_type}_content_repeater` bevat, zoals `location_content_repeater`.
- Gebruikt een vaste bedrijfsbriefing voor doelgroep, diensten, positionering, tone of voice, terminologie en controleerbare claims.
- Kan maximaal tien geselecteerde bronpagina’s als aanvullende bedrijfscontext gebruiken.
- Ondersteunt bestaande tekst-, WYSIWYG- en afbeeldingsvelden.
- ACF-galerijen kunnen zonder tussentijds sluiten worden aangevuld, opgeschoond en via slepen gesorteerd; de mediabibliotheek werkt daarbij als veilige toevoegmodus.
- Afbeeldingen kunnen in het chatvenster worden geüpload.
- De chat blijft tijdens de bewerksessie open en bewaart de laatste 100 berichten per pagina in de browsertab.
- Meerdere opdrachten worden zonder paginaherlading direct als visuele preview gestapeld.
- Met **Aanwijzen** kan een zichtbare tekst, knop of afbeelding aan het exacte ACF-veld worden gekoppeld.
- Het venster kan via de titelbalk worden versleept en met de minknop worden ingeklapt.
- Wijzigingen zijn eerst een gebruikersgebonden preview van twee uur.
- **Wijzigingen opslaan** en **Alles ongedaan maken** zijn expliciete handelingen.
- Alleen bevoegde gebruikers krijgen toegang: `edit_post` op gewone pagina’s en `edit_posts` op post-typearchieven.
- Post types kunnen via de instellingen volledig van de frontend-assistent worden uitgesloten.
- Dynamisch gekoppelde berichten en Gravity Forms-formulieren krijgen waar mogelijk een directe beheerlink; beide soorten links zijn afzonderlijk uit te schakelen via de instellingen.
- Bij overlappende sliderkaarten wordt alleen de beheerlink van de actieve, zichtbare kaart getoond.

## Bewuste beperkingen

- De chat wijzigt één concreet veld per opdracht.
- Layouts toevoegen, verwijderen of verplaatsen wordt niet ondersteund.
- Dynamische relaties worden in de pagina-inventaris zichtbaar, maar vrije querylogica in templates wordt nog niet volledig geanalyseerd.
- Een lokale WordPress-database en API-key zijn nodig voor een end-to-end test.
