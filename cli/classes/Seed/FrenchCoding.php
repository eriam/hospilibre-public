<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Cli\Seed\Decor\ArrayCoding;
use Ox\Cli\Seed\Decor\ClinicalCoding;

/**
 * Codage français : CCAM pour les actes, CIM-10 pour les diagnostics.
 *
 * Pendant de `Specialities` pour la nomenclature : celle-là décrit les unités d'un établissement
 * français, celle-ci ce qu'on y code. Les deux décors français livrés s'en servent, et c'est possible
 * parce que la table est indexée par LIBELLÉ D'ACTE et non par spécialité — « Cure de canal carpien »
 * se code pareil dans un CHU et dans une clinique.
 *
 * D'OÙ VIENNENT CES CODES. Chacun a été cherché DANS le dictionnaire monté, pas écrit de mémoire : une
 * requête par acte sur `p_acte.LIBELLELONG`, une par diagnostic sur `master.abbrev`, et le libellé
 * officiel relu. Ce libellé est reporté en commentaire à côté de chaque code — c'est la seule façon
 * pour un relecteur de juger l'appariement sans monter de base. Relire ces libellés a payé deux fois :
 * `K433` était une hernie PÉRISTOMIALE et non une éventration, et `R296` existe dans le dictionnaire
 * sans aucun libellé français.
 *
 * DEUX RENONCEMENTS ASSUMÉS :
 *
 * 1. le libellé de l'intervention reste le libellé COURT et lisible du décor, pas celui de la CCAM.
 *    « Prothèse totale de hanche » est ce qu'une secrétaire saisit ; « Remplacement de l'articulation
 *    coxofémorale par prothèse totale » est ce que le code porte. Les deux coexistent dans un vrai
 *    dossier, et le second est déductible du premier ;
 * 2. les codes ne sont pas datés. La CCAM et la CIM-10 sont révisées ; un code retiré resterait ici.
 *    Le test de conformité au dictionnaire le dirait — il vérifie aussi `DATEFIN`.
 */
abstract class FrenchCoding
{
    /**
     * Code CCAM de chaque acte des décors français, par libellé.
     *
     * Le libellé officiel du code est en commentaire. Quand il est plus précis que le libellé du décor
     * — et il l'est presque toujours — c'est l'acte le plus courant de la famille qui a été retenu :
     * la voie coelioscopique pour une cholécystectomie, la prothèse tricompartimentaire pour un genou.
     *
     * @var array<string, string>
     */
    private const CCAM = [
        // Chirurgie viscérale
        'Cholécystectomie sous cœlioscopie'      => 'HMFC004', // Cholécystectomie, par coelioscopie
        'Appendicectomie'                        => 'HHFA016', // Appendicectomie, par coelioscopie ou par laparotomie avec préparation par coelioscopie
        'Cure de hernie inguinale'               => 'LMMA012', // Cure unilatérale d'une hernie de l'aine avec pose de prothèse, par abord inguinal
        'Résection colique segmentaire'          => 'HHFA017', // Colectomie gauche sans libération de l'angle colique gauche, avec rétablissement de la continuité
        "Cure d'éventration"                     => 'LMMA004', // Cure d'éventration postopératoire de la paroi abdominale antérieure avec pose de prothèse, par laparotomie
        'Colectomie droite'                      => 'HHFA008', // Colectomie droite avec rétablissement de la continuité, par coelioscopie ou par laparotomie avec préparation
        'Gastrectomie partielle'                 => 'HFFA006', // Gastrectomie partielle inférieure avec anastomose gastrojéjunale, par laparotomie
        "Cure d'hémorroïdes"                     => 'EGFA001', // Hémorroïdectomie pédiculaire semifermée ou fermée

        // Chirurgie orthopédique
        'Prothèse totale de hanche'              => 'NEKA020', // Remplacement de l'articulation coxofémorale par prothèse totale
        'Prothèse totale de genou'               => 'NFKA008', // Remplacement de l'articulation du genou par prothèse tricompartimentaire
        'Arthroscopie du genou'                  => 'NFQC001', // Exploration de l'articulation du genou, par arthroscopie
        'Ostéosynthèse de fracture du poignet'   => 'MCCA005', // Ostéosynthèse de fracture de l'extrémité distale d'un os de l'avant-bras, à foyer ouvert
        "Ablation de matériel d'ostéosynthèse"   => 'NBGA007', // Ablation de matériel d'ostéosynthèse du fémur, par abord direct
        'Cure de canal carpien'                  => 'AHPA009', // Libération du nerf médian au canal carpien, par abord direct
        'Réparation de la coiffe des rotateurs'  => 'MJEC001', // Réinsertion ou suture d'un tendon de la coiffe des rotateurs de l'épaule, par arthroscopie
        "Arthrodèse de l'avant-pied"             => 'NHDA004', // Arthrodèse de la première articulation métatarsophalangienne, par arthrotomie

        // Gynécologie-obstétrique
        'Césarienne'                             => 'JQGA002', // Accouchement par césarienne programmée, par laparotomie
        'Hystérectomie par voie vaginale'        => 'JKFA026', // Hystérectomie totale, par abord vaginal
        'Cœlioscopie diagnostique'               => 'ZCQC002', // Exploration de la cavité abdominale, par coelioscopie [Coelioscopie exploratrice]
        'Conisation du col'                      => 'JKFA031', // Conisation du col de l'utérus
        'Kystectomie ovarienne'                  => 'JJFC004', // Résection partielle de l'ovaire, par coelioscopie
        'Curetage évacuateur'                    => 'JNJD002', // Évacuation d'un utérus gravide par aspiration et/ou curetage, au 1er trimestre de la grossesse
        'Ligature de trompes'                    => 'JJPC003', // Interruption unilatérale ou bilatérale de la perméabilité des trompes utérines, par coelioscopie

        // Urologie
        'Résection endoscopique de prostate'     => 'JGFE023', // Résection d'une hypertrophie de la prostate sans laser, par urétrocystoscopie
        'Urétéroscopie pour calcul'              => 'JCGE001', // Ablation et/ou fragmentation de calcul de l'uretère pelvien, par urétéroscopie rétrograde
        'Néphrectomie partielle'                 => 'JAFC005', // Néphrectomie partielle, par coelioscopie ou par rétropéritonéoscopie
        'Cure de varicocèle'                     => 'EGFA010', // Résection ou ligature de la veine testiculaire, par abord direct
        'Pose de sonde double J'                 => 'JCLE002', // Pose d'une endoprothèse urétérale, par endoscopie rétrograde
        'Circoncision'                           => 'JHFA009', // Posthectomie

        // ORL
        'Amygdalectomie'                         => 'FAFA014', // Amygdalectomie par dissection
        'Septoplastie'                           => 'GAMA007', // Septoplastie nasale
        "Pose d'aérateurs transtympaniques"      => 'CBLD001', // Pose bilatérale d'aérateur transtympanique
        'Thyroïdectomie totale'                  => 'KCFA005', // Thyroïdectomie totale, par cervicotomie
        'Méatotomie maxillaire'                  => 'GBPE001', // Méatotomie nasale moyenne, par endoscopie
        'Adénoïdectomie'                         => 'FAFA008', // Adénoïdectomie

        // Ophtalmologie
        // BFGA004 a été RETIRÉ de la CCAM le 11/05/2019 ; c'est le test de conformité au dictionnaire
        // qui l'a signalé, en lisant DATEFIN. Son successeur distingue la pose d'un dispositif de
        // drainage trabéculaire ; la cataracte simple est celle qui n'en pose pas.
        'Phacoémulsification avec implant'       => 'BFGA427', // Extraction extracapsulaire du cristallin par phakoémulsification, avec implantation de cristallin artificiel dans la chambre postérieure de l'oeil, sans implantation de dispositif de drainage trabéculaire
        'Vitrectomie'                            => 'BGFA001', // Vitrectomie par sclérotomie postérieure, sans coagulation de la rétine
        'Chirurgie du ptérygion'                 => 'BCFA009', // Exérèse primitive de ptérygion, sans autogreffe
        'Trabéculectomie'                        => 'BEFA008', // Trabéculectomie [Sclérectomie transfixiante]
        'Chirurgie du strabisme'                 => 'BJMA002', // Renforcement, affaiblissement ou déplacement de l'insertion d'un muscle oculomoteur droit
        'Injection intravitréenne'               => 'BGLB001', // Injection d'agent pharmacologique dans le corps vitré

        // Chirurgie vasculaire
        'Éveinage de varices'                    => 'EJGA002', // Extraction [Stripping] de la grande veine saphène, par abord direct
        'Pontage fémoro-poplité'                 => 'EECA001', // Pontage artériel fémoropoplité au-dessus de l'interligne articulaire du genou, par abord direct
        "Cure d'anévrisme de l'aorte"            => 'DGKA004', // Remplacement de l'aorte abdominale ou de l'artère iliaque commune, par laparotomie
        'Endartériectomie carotidienne'          => 'EBFA003', // Thromboendartériectomie de l'artère carotide commune, par cervicotomie
        'Création de fistule artério-veineuse'   => 'EKLA001', // Pose d'une dérivation artérioveineuse au membre supérieur, par abord direct

        // Neurochirurgie
        'Cure de hernie discale lombaire'        => 'LFFA002', // Exérèse d'une hernie discale de la colonne vertébrale lombale, par abord postérieur ou postérolatéral
        'Laminectomie décompressive'             => 'LHFA016', // Laminectomie vertébrale sans exploration du contenu intradural, par abord postérieur
        'Dérivation ventriculo-péritonéale'      => 'ABCA002', // Dérivation péritonéale ou atriale du liquide cérébrospinal ventriculaire, par abord direct
        'Exérèse de méningiome'                  => 'ACFA002', // Exérèse de tumeur extraparenchymateuse de la convexité du cerveau sans atteinte de sinus veineux

        // Chirurgie thoracique
        'Lobectomie pulmonaire'                  => 'GFFA013', // Lobectomie pulmonaire, par thoracotomie
        'Médiastinoscopie'                       => 'GHQC001', // Exploration du médiastin, par médiastinoscopie
        'Pleurodèse'                             => 'GGPA002', // Libération du poumon [Pneumolyse] pour symphyse pleurale
        'Exérèse de nodule pulmonaire'           => 'GFFA017', // Exérèse partielle non anatomique unique du poumon, par thoracotomie

        // Chirurgie pédiatrique
        'Cure de hernie inguinale du nourrisson' => 'LMMA017', // Cure unilatérale d'une hernie de l'aine sans pose de prothèse sous anesthésie générale ou locorégionale
        'Orchidopexie'                           => 'JHDA001', // Orchidopexie, par abord scrotal
        "Cure d'invagination"                    => 'HGEA003', // Réduction d'invagination intestinale aigüe, par laparotomie

        // Chirurgie plastique
        'Abdominoplastie'                        => 'QBFA011', // Dermolipectomie abdominale sans transposition de l'ombilic
        'Reconstruction mammaire'                => 'QEMA006', // Reconstruction du sein par pose d'implant prothétique
        'Exérèse de lésion cutanée'              => 'QZFA004', // Exérèse de lésion superficielle de la peau par excision d'une zone cutanée de 5 cm² à 10 cm²
        'Greffe de peau mince'                   => 'QZEA006', // Autogreffe de peau totale sur plusieurs localisations

        // Chirurgie maxillo-faciale
        'Extraction de dents de sagesse'         => 'HBGD171', // Avulsion de 4 odontoïdes inclus ou de 4 dents surnuméraires à l'état de germe
        'Ostéotomie maxillaire'                  => 'LBPA022', // Ostéotomie maxillaire type hémi-Le Fort I, par abord intrabuccal
        'Réduction de fracture zygomatique'      => 'LACA002', // Ostéosynthèse de fracture de l'os zygomatique [malaire], à foyer ouvert
        'Exérèse de kyste mandibulaire'          => 'LBFA030', // Exérèse de lésion de l'os maxillaire et/ou du corps de la mandibule de 2 cm à 4 cm de grand axe

        // Chirurgie bariatrique
        'Sleeve gastrectomie'                    => 'HFFC018', // Gastrectomie longitudinale [Sleeve gastrectomy] pour obésité morbide, par coelioscopie
        'Bypass gastrique'                       => 'HFCA001', // Court-circuit gastrique avec anse montée en Y [Bypass gastrique en Y] pour obésité morbide
        "Pose d'anneau gastrique"                => 'HFMA009', // Gastroplastie par pose d'anneau ajustable périgastrique pour obésité morbide, par laparotomie
        "Ablation d'anneau gastrique"            => 'HFMC008', // Repositionnement ou ablation d'un anneau ajustable périgastrique, par coelioscopie

        // Chirurgie ambulatoire, et les actes que la clinique partage avec elle
        'Exérèse de lipome'                      => 'QZFA007', // Exérèse de lésion souscutanée susfasciale de 3 cm à 10 cm de grand axe
        'Cure de hernie ombilicale'              => 'LMMA009', // Cure de hernie de la paroi abdominale antérieure après l'âge de 16 ans sans pose de prothèse
        'Ablation de kyste sébacé'               => 'QZFA036', // Exérèse de lésion superficielle de la peau par excision d'une zone cutanée de moins de 5 cm²
        'Biopsie ganglionnaire'                  => 'FCFA021', // Exérèse de noeud [ganglion] lymphatique des membres à visée diagnostique, par abord direct

        // Actes propres au décor de clinique chirurgicale
        'Cure de doigt à ressaut'                => 'MJPA013', // Section ou plastie d'agrandissement de la partie cruciforme de la gaine fibreuse digitale
        'Exérèse de kyste synovial'              => 'MGFA007', // Exérèse de kyste synovial du poignet, par abord direct
        "Arthroscopie de l'épaule"               => 'MEQC002', // Exploration de l'articulation de l'épaule, par arthroscopie
        'Exérèse de kyste sacro-coccygien'       => 'QBFA007', // Excision d'un sinus pilonidal périnéofessier
    ];

    /**
     * Diagnostics d'admission, par unité. Forme `abbrev` : sans point.
     *
     * Ce sont des motifs d'ENTRÉE et non des complications : ce qu'un service inscrit sur un séjour à
     * l'admission. Les unités chirurgicales en ont aussi, parce que tous leurs séjours ne sont pas
     * opérés — un tiers environ dans les profils livrés.
     *
     * @var array<string, string[]>
     */
    private const DIAGNOSES = [
        // --- Accueil et médecine ---
        'urgences'                  => ['R100', 'S060', 'J069', 'A099', 'R55', 'S525', 'R073', 'F100'],
        'uhcd'                      => ['R55', 'R100', 'F100', 'E86', 'J189', 'N390'],
        'medecine-interne'          => ['R509', 'M059', 'D649', 'E871', 'I10'],
        'medecine-polyvalente'      => ['R509', 'I10', 'J189', 'E119', 'N390'],
        'cardiologie'               => ['I219', 'I500', 'I48', 'I200', 'I10'],
        'soins-intensifs-cardio'    => ['I219', 'I200', 'I469', 'I472', 'I500'],
        'usic'                      => ['I219', 'I200', 'I469', 'I472', 'I500'],
        'reanimation'               => ['J960', 'A419', 'R572', 'J80', 'N179'],
        'pneumologie'               => ['J189', 'J440', 'J459', 'J939', 'I269'],
        'gastro-enterologie'        => ['K922', 'K259', 'K519', 'K509', 'K802'],
        'hepatologie'               => ['K703', 'B182', 'K729', 'K766', 'K746'],
        'neurologie'                => ['I639', 'G409', 'G35', 'G459', 'G629'],
        'nephrologie'               => ['N185', 'N039', 'N179', 'E875', 'N390'],
        'endocrinologie'            => ['E119', 'E101', 'E059', 'E039', 'E274'],
        'diabetologie'              => ['E119', 'E101', 'E105', 'E114'],
        'rhumatologie'              => ['M545', 'M069', 'M109', 'M810'],
        'oncologie'                 => ['C509', 'C349', 'C189', 'C61', 'Z511'],
        'hematologie'               => ['D649', 'C910', 'D693', 'C819', 'D509'],
        'geriatrie'                 => ['F03', 'S7200', 'I639', 'E86', 'N390'],
        'dermatologie'              => ['L409', 'L309', 'L020', 'C449', 'L519'],
        'infectiologie'             => ['A419', 'A099', 'J189', 'N390', 'L020'],
        'allergologie'              => ['J304', 'T783', 'L509', 'J459', 'T784'],
        'medecine-vasculaire'       => ['I802', 'I839', 'I269', 'I739', 'I878'],
        'medecine-physique'         => ['I639', 'M545', 'Z509', 'G819'],
        'medecine-nucleaire'        => ['E059', 'C73', 'Z511', 'M810'],
        'algologie'                 => ['M545', 'R522', 'G500'],
        'soins-palliatifs'          => ['Z515', 'C349', 'C189', 'C509', 'C61'],
        'pediatrie'                 => ['J210', 'A099', 'J039', 'R509', 'J459'],

        // --- Unités chirurgicales : motifs d'entrée, opérés ou non ---
        'chirurgie-viscerale'       => ['K358', 'K802', 'K409', 'K439', 'K562', 'C189'],
        'chirurgie-orthopedique'    => ['M160', 'M170', 'S525', 'M754', 'G560', 'M201'],
        'gynecologie-obstetrique'   => ['O80', 'O820', 'O140', 'O039', 'O479', 'N921', 'D259'],
        'urologie'                  => ['N40', 'N201', 'C64', 'N433', 'N133'],
        'orl'                       => ['J350', 'J342', 'H659', 'E041', 'J320', 'J352'],
        'ophtalmologie'             => ['H259', 'H401', 'H332', 'H110', 'H501'],
        'chirurgie-vasculaire'      => ['I839', 'I702', 'I714', 'I652', 'N185'],
        'neurochirurgie'            => ['M511', 'M480', 'G919', 'D320'],
        'chirurgie-thoracique'      => ['C349', 'J939', 'J869', 'R91'],
        'chirurgie-pediatrique'     => ['K409', 'Q539', 'N47', 'K358', 'K561'],
        'chirurgie-plastique'       => ['C449', 'C509', 'E660', 'T950', 'L905'],
        'chirurgie-maxillo-faciale' => ['K010', 'K074', 'S024', 'K090'],
        'chirurgie-bariatrique'     => ['E660', 'E662', 'E668', 'E119'],
        'chirurgie-ambulatoire'     => ['D179', 'K429', 'L720', 'R590'],

        // --- Unités du décor de clinique chirurgicale ---
        'clinique-ambulatoire'      => ['D179', 'K429', 'L720', 'R590', 'G560'],
        'clinique-orthopedie'       => ['M170', 'M654', 'M674', 'M754'],
        'clinique-viscerale'        => ['K409', 'K802', 'K649', 'L059'],
        'clinique-ophtalmologie'    => ['H259', 'H110', 'H501', 'H431'],
        'clinique-medecine'         => ['R509', 'I10', 'E119', 'M545'],
        'clinique-surveillance'     => ['R572', 'J960', 'I500', 'N179'],
    ];

    /**
     * Diagnostic qui MOTIVE chaque acte.
     *
     * C'est la table qui empêche l'incohérence visible : sans elle, le diagnostic serait tiré dans la
     * liste de l'unité indépendamment de l'acte, et une orthopédie poserait une prothèse de hanche à un
     * patient entré pour un syndrome du canal carpien. Les listes sont courtes exprès — un acte n'a
     * qu'une poignée d'indications.
     *
     * @var array<string, string[]>
     */
    private const PROCEDURE_DIAGNOSES = [
        'Cholécystectomie sous cœlioscopie'      => ['K802'],
        'Appendicectomie'                        => ['K358', 'K35'],
        'Cure de hernie inguinale'               => ['K409'],
        'Résection colique segmentaire'          => ['C189', 'K573'],
        "Cure d'éventration"                     => ['K439'],
        'Colectomie droite'                      => ['C189'],
        'Gastrectomie partielle'                 => ['C169', 'K259'],
        "Cure d'hémorroïdes"                     => ['K649'],

        'Prothèse totale de hanche'              => ['M160', 'S7200'],
        'Prothèse totale de genou'               => ['M170'],
        'Arthroscopie du genou'                  => ['M232', 'S836'],
        'Ostéosynthèse de fracture du poignet'   => ['S525'],
        "Ablation de matériel d'ostéosynthèse"   => ['Z479', 'T841'],
        'Cure de canal carpien'                  => ['G560'],
        'Réparation de la coiffe des rotateurs'  => ['M751', 'M754'],
        "Arthrodèse de l'avant-pied"             => ['M201'],

        'Césarienne'                             => ['O820', 'O140'],
        'Hystérectomie par voie vaginale'        => ['D251', 'N921'],
        'Cœlioscopie diagnostique'               => ['N830', 'N921'],
        'Conisation du col'                      => ['N871', 'D060'],
        'Kystectomie ovarienne'                  => ['N830', 'D27'],
        'Curetage évacuateur'                    => ['O039', 'O021'],
        'Ligature de trompes'                    => ['Z302'],

        'Résection endoscopique de prostate'     => ['N40'],
        'Urétéroscopie pour calcul'              => ['N201'],
        'Néphrectomie partielle'                 => ['C64'],
        'Cure de varicocèle'                     => ['I861'],
        'Pose de sonde double J'                 => ['N201', 'N133'],
        'Circoncision'                           => ['N47'],

        'Amygdalectomie'                         => ['J350'],
        'Septoplastie'                           => ['J342'],
        "Pose d'aérateurs transtympaniques"      => ['H653', 'H659'],
        'Thyroïdectomie totale'                  => ['E041', 'C73'],
        'Méatotomie maxillaire'                  => ['J320'],
        'Adénoïdectomie'                         => ['J352'],

        'Phacoémulsification avec implant'       => ['H259'],
        'Vitrectomie'                            => ['H332', 'H431'],
        'Chirurgie du ptérygion'                 => ['H110'],
        'Trabéculectomie'                        => ['H401'],
        'Chirurgie du strabisme'                 => ['H501', 'H500'],
        'Injection intravitréenne'               => ['H431'],

        'Éveinage de varices'                    => ['I839'],
        'Pontage fémoro-poplité'                 => ['I702'],
        "Cure d'anévrisme de l'aorte"            => ['I714'],
        'Endartériectomie carotidienne'          => ['I652'],
        'Création de fistule artério-veineuse'   => ['N185'],

        'Cure de hernie discale lombaire'        => ['M511'],
        'Laminectomie décompressive'             => ['M480'],
        'Dérivation ventriculo-péritonéale'      => ['G919'],
        'Exérèse de méningiome'                  => ['D320'],

        'Lobectomie pulmonaire'                  => ['C349'],
        'Médiastinoscopie'                       => ['C349', 'R591'],
        'Pleurodèse'                             => ['J939', 'J869'],
        'Exérèse de nodule pulmonaire'           => ['R91', 'C349'],

        'Cure de hernie inguinale du nourrisson' => ['K409'],
        'Orchidopexie'                           => ['Q539'],
        "Cure d'invagination"                    => ['K561'],

        'Abdominoplastie'                        => ['E660'],
        'Reconstruction mammaire'                => ['C509'],
        'Exérèse de lésion cutanée'              => ['C449', 'D485'],
        'Greffe de peau mince'                   => ['T950', 'L989'],

        'Extraction de dents de sagesse'         => ['K010'],
        'Ostéotomie maxillaire'                  => ['K074'],
        'Réduction de fracture zygomatique'      => ['S024'],
        'Exérèse de kyste mandibulaire'          => ['K090'],

        'Sleeve gastrectomie'                    => ['E662', 'E660'],
        'Bypass gastrique'                       => ['E662'],
        "Pose d'anneau gastrique"                => ['E660'],
        "Ablation d'anneau gastrique"            => ['E660'],

        'Exérèse de lipome'                      => ['D179'],
        'Cure de hernie ombilicale'              => ['K429'],
        'Ablation de kyste sébacé'               => ['L720'],
        'Biopsie ganglionnaire'                  => ['R590', 'C859'],

        'Cure de doigt à ressaut'                => ['M654'],
        'Exérèse de kyste synovial'              => ['M674'],
        "Arthroscopie de l'épaule"               => ['M754'],
        'Exérèse de kyste sacro-coccygien'       => ['L059'],
    ];

    /**
     * Actes réalisables en hospitalisation de JOUR.
     *
     * La liste est volontairement conservatrice — un acte absent d'ici n'interdit rien, il force
     * simplement l'hospitalisation complète — et chaque spécialité chirurgicale y figure au moins une
     * fois, sans quoi ses séjours de jour n'auraient plus aucun acte à recevoir et se rabattraient sur
     * la liste complète, ce qui annulerait la contrainte.
     *
     * TROIS CAS LIMITES ASSUMÉS : la cure de hernie discale lombaire, la médiastinoscopie et l'ablation
     * d'anneau gastrique se font en ambulatoire dans des centres entraînés mais pas partout. Elles sont
     * retenues parce qu'elles sont le seul acte de jour de leur spécialité — neurochirurgie, chirurgie
     * thoracique et chirurgie bariatrique — et qu'une spécialité sans acte de jour annule la contrainte
     * par repli. À l'inverse, l'ostéosynthèse d'une fracture zygomatique à foyer ouvert en a été retirée :
     * elle passe la nuit, et la chirurgie maxillo-faciale garde deux autres actes de journée.
     *
     * @var string[]
     */
    private const AMBULATORY = [
        // Viscéral
        'Cure de hernie inguinale',
        "Cure d'hémorroïdes",
        'Cholécystectomie sous cœlioscopie',
        // Orthopédie
        'Arthroscopie du genou',
        "Ablation de matériel d'ostéosynthèse",
        'Cure de canal carpien',
        'Réparation de la coiffe des rotateurs',
        "Arthrodèse de l'avant-pied",
        // Gynécologie
        'Cœlioscopie diagnostique',
        'Conisation du col',
        'Curetage évacuateur',
        'Ligature de trompes',
        'Kystectomie ovarienne',
        // Urologie
        'Pose de sonde double J',
        'Circoncision',
        'Cure de varicocèle',
        'Urétéroscopie pour calcul',
        // ORL
        'Amygdalectomie',
        'Septoplastie',
        "Pose d'aérateurs transtympaniques",
        'Méatotomie maxillaire',
        'Adénoïdectomie',
        // Ophtalmologie : tout s'y fait en journée
        'Phacoémulsification avec implant',
        'Vitrectomie',
        'Chirurgie du ptérygion',
        'Trabéculectomie',
        'Chirurgie du strabisme',
        'Injection intravitréenne',
        // Vasculaire
        'Éveinage de varices',
        // Neurochirurgie et thoracique : les deux cas limites
        'Cure de hernie discale lombaire',
        'Médiastinoscopie',
        // Pédiatrie
        'Cure de hernie inguinale du nourrisson',
        'Orchidopexie',
        // Plastique
        'Exérèse de lésion cutanée',
        'Greffe de peau mince',
        // Maxillo-facial
        'Extraction de dents de sagesse',
        'Exérèse de kyste mandibulaire',
        // Bariatrique : seule la reprise d'anneau se fait en journée
        "Ablation d'anneau gastrique",
        // Ambulatoire et clinique
        'Exérèse de lipome',
        'Cure de hernie ombilicale',
        'Ablation de kyste sébacé',
        'Biopsie ganglionnaire',
        'Cure de doigt à ressaut',
        'Exérèse de kyste synovial',
        "Arthroscopie de l'épaule",
        'Exérèse de kyste sacro-coccygien',
    ];

    /**
     * @throws SeedException
     */
    public static function coding(): ClinicalCoding
    {
        return new ArrayCoding(self::CCAM, self::DIAGNOSES, self::PROCEDURE_DIAGNOSES, self::AMBULATORY);
    }

    /**
     * Actes réalisables en journée, pour le test qui vérifie que chaque spécialité en a au moins un.
     *
     * @return string[]
     */
    public static function ambulatoryProcedures(): array
    {
        return self::AMBULATORY;
    }

    /**
     * Tous les codes CCAM déclarés, pour le test de conformité au dictionnaire.
     *
     * @return string[]
     */
    public static function ccamCodes(): array
    {
        return array_values(array_unique(self::CCAM));
    }

    /**
     * Tous les codes CIM-10 déclarés, pour le test de conformité au dictionnaire.
     *
     * @return string[]
     */
    public static function cim10Codes(): array
    {
        $codes = [];

        foreach ([self::DIAGNOSES, self::PROCEDURE_DIAGNOSES] as $table) {
            foreach ($table as $list) {
                foreach ($list as $code) {
                    $codes[$code] = true;
                }
            }
        }

        return array_keys($codes);
    }
}
