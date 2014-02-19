tresholds-governor
==================

Tresholds Governor, aims to facilitate the protection of authentication against brute force and dictionary attacks

INTRODUCTION
------------
The OWASP Guide states "Applications MUST protect credentials from common authentication attacks as detailed 
in the Testing Guide". This library aims to protect user credentials from  
these authentication attacks. It is based on the "Tresholds Governer" described in the OWASP Guide.

FEATURES
--------

- Framework-independent component to be used from Framework-specific bundle/component or from applications that
  impelent their own authentication

- Registers authentication counts and decedes to Blocks by both username and client ip address for 
  which authentication failed  too often,
 
- To hide weather an account actually exists for a username, any username that is tried too often may be blocked, 
  regardless of the existence and status of an account with that username,

- Facilitates a logical difference between failed login lockout and eventual administrative lockout, 
  so that re-enabling all usernames en masse does not unlock administratively locked users (OWASP requirement).

- Automatic release of username on authentication success,

- Stores counters instead of individual requests to prevent database flooding from brute force attacks.

REQUIREMENTS
------------
PHP >=5.3.3, Doctrine >=2.2.3 (actually only dbal is used, but doctrine/doctrine-bundle is still required) 
and was tested with MySQL 5.5.

RELEASE NOTES
-------------

This is a pre-release version under development. 

May be vurnerable to user enumeration through timing attacks because of differences in database query performance 
for frequently and infrequently used usernames,

Does not garbage-collect nor pack stored RequestCounts. 

DOCUMENTATION
-------------
- [Installation and configuration](doc/Installation.md)
- [Counting and deciding](doc/Counting and deciding.md)
	
SUPPORT
-------

MetaClass offers help and support on a commercial basis with 
the application and extension of this bundle and additional 
security measures.

http://www.metaclass.nl/site/index_php/Menu/10/Contact.html


COPYRIGHT AND LICENCE
---------------------

Unless notified otherwise Copyright (c) 2014 MetaClass Groningen 

This bundle is under the MIT license. See the complete license in the bundle:

	meta/LICENSE

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.